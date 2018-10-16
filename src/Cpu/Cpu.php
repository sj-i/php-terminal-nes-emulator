<?php
namespace Nes\Cpu;

use Nes\Bus\CpuBus;
use Nes\Cpu\Registers\Registers;
use Nes\Debugger;

class Cpu
{
    /** @var \Nes\Cpu\Registers\Registers */
    public $registers;
    /** @var bool */
    public $hasBranched;
    /** @var \Nes\Bus\CpuBus */
    public $bus;
    /** @var \Nes\Cpu\OpCodeProps[] */
    public $opCodeList;
    /** @var \Nes\Cpu\Interrupts */
    public $interrupts;

    const CPU_CLOCK = 1789772.5;

    public function __construct(CpuBus $bus, Interrupts $interrupts)
    {
        $this->bus = $bus;
        $this->interrupts = $interrupts;

        $this->registers = Registers::getDefault();
        $this->hasBranched = false;
        $this->opCodeList = [];

        $opCodes = OpCode::getOpCodes();
        foreach ($opCodes as $key => $op) {
            $this->opCodeList[hexdec($key)] = $op;
        }
    }


    public function reset()
    {
        $this->registers = Registers::getDefault();
        // TODO: flownes set 0x8000 to PC when read(0xfffc) fails.
        $this->registers->pc = $this->readWord(0xFFFC);
        printf("Initial pc: %04x\n", $this->registers->pc);
    }

    /**
     * @param int $mode
     *
     * @return array
     * @throws \Exception
     */
    public function getAddrOrDataWithAdditionalCycle(int $mode): array
    {
        switch ($mode) {
            case Addressing::Accumulator:
                return [0x00, 0];
            case Addressing::Implied:
                return [0x00, 0];
            case Addressing::Immediate:
                return [$this->fetchByte($this->registers->pc), 0];
            case Addressing::Relative:
                $baseAddr = $this->fetchByte($this->registers->pc);
                $addr = $baseAddr < 0x80 ? $baseAddr + $this->registers->pc : $baseAddr + $this->registers->pc - 256;
                return [
                    $addr,
                    ($addr & 0xff00) !== ($this->registers->pc & 0xFF00) ? 1 : 0
                ];
            case Addressing::ZeroPage:
                return [$this->fetchByte($this->registers->pc), 0];
            case Addressing::ZeroPageX:
                $addr = $this->fetchByte($this->registers->pc);
                return [
                    ($addr + $this->registers->x) & 0xff,
                    0
                ];
            case Addressing::ZeroPageY:
                $addr = $this->fetchByte($this->registers->pc);
                return [($addr + $this->registers->y & 0xff), 0];
            case Addressing::Absolute:
                return [($this->fetchWord($this->registers->pc)), 0];
            case Addressing::AbsoluteX:
                $addr = ($this->fetchWord($this->registers->pc));
                $additionalCycle = ($addr & 0xFF00) !== (($addr + $this->registers->x) & 0xFF00) ? 1 : 0;
                return [($addr + $this->registers->x) & 0xFFFF, $additionalCycle];
            case Addressing::AbsoluteY:
                $addr = ($this->fetchWord($this->registers->pc));
                $additionalCycle = ($addr & 0xFF00) !== (($addr + $this->registers->y) & 0xFF00) ? 1 : 0;
                return [($addr + $this->registers->y) & 0xFFFF, $additionalCycle];
            case Addressing::PreIndexedIndirect:
                $baseAddr = ($this->fetchByte($this->registers->pc) + $this->registers->x) & 0xFF;
                $addr = $this->readByte($baseAddr) + ($this->readByte(($baseAddr + 1) & 0xFF) << 8);
                return [
                    $addr & 0xFFFF,
                    ($addr & 0xFF00) !== ($baseAddr & 0xFF00) ? 1 : 0
                ];
            case Addressing::PostIndexedIndirect:
                $addrOrData = $this->fetchByte($this->registers->pc);
                $baseAddr = $this->readByte($addrOrData) + ($this->readByte(($addrOrData + 1) & 0xFF) << 8);
                $addr = $baseAddr + $this->registers->y;
                return [
                    $addr & 0xFFFF,
                    ($addr & 0xFF00) !== ($baseAddr & 0xFF00) ? 1 : 0
                ];
            case Addressing::IndirectAbsolute:
                $addrOrData = $this->fetchWord($this->registers->pc);
                $addr = $this->readByte($addrOrData) +
                    ($this->readByte(($addrOrData & 0xFF00) | ((($addrOrData & 0xFF) + 1) & 0xFF)) << 8);
                return [$addr & 0xFFFF, 0];
            default:
                echo($mode);
                throw new \Exception(`Unknown addressing $mode detected.`);
        }
    }

    private function fetchByte(int $addr): int
    {
        $this->registers->pc += 1;

        return $this->bus->readByCpu($addr & 0xFFFF);
    }

    private function fetchWord(int $addr): int
    {
        $this->registers->pc += 2;

        return $this->readWord($addr);
    }

    private function readByte(int $addr): int
    {
        $addr &= 0xFFFF;

        return $this->bus->readByCpu($addr);
    }

    private function readWord(int $addr): int
    {
        $addr &= 0xFFFF;

        return ($this->bus->readByCpu($addr) | $this->bus->readByCpu($addr + 1) << 8);
    }

    public function write(int $addr, int $data)
    {
        $this->bus->writeByCpu($addr, $data);
    }

    public function push(int $data)
    {
        $this->write(0x100 | ($this->registers->sp & 0xFF), $data);
        $this->registers->sp--;
    }

    public function pop(): int
    {
        $this->registers->sp++;

        return $this->readByte(0x100 | ($this->registers->sp & 0xFF));
    }

    public function branch(int $addr)
    {
        $this->registers->pc = $addr;
        $this->hasBranched = true;
    }

    public function pushStatus()
    {
        $status = (+$this->registers->p->negative) << 7 |
            (+$this->registers->p->overflow) << 6 |
            (+$this->registers->p->reserved) << 5 |
            (+$this->registers->p->break_mode) << 4 |
            (+$this->registers->p->decimal_mode) << 3 |
            (+$this->registers->p->interrupt) << 2 |
            (+$this->registers->p->zero) << 1 |
            (+$this->registers->p->carry);
        $this->push($status);
    }

    public function popStatus()
    {
        $status = $this->pop();
        $this->registers->p->negative = !!($status & 0x80);
        $this->registers->p->overflow = !!($status & 0x40);
        $this->registers->p->reserved = !!($status & 0x20);
        $this->registers->p->break_mode = !!($status & 0x10);
        $this->registers->p->decimal_mode = !!($status & 0x08);
        $this->registers->p->interrupt = !!($status & 0x04);
        $this->registers->p->zero = !!($status & 0x02);
        $this->registers->p->carry = !!($status & 0x01);
    }

    public function popPC()
    {
        $this->registers->pc = $this->pop();
        $this->registers->pc += ($this->pop() << 8);
    }

    /**
     * @param string $baseName
     * @param int $addrOrData
     * @param int $mode
     *
     * @throws \Exception
     */
    public function execInstruction(string $baseName, int $addrOrData, int $mode)
    {
        $this->hasBranched = false;
        switch ($baseName) {
            case 'LDA':
                $this->registers->a = ($mode == Addressing::Immediate) ? $addrOrData : $this->readByte($addrOrData);
                $this->registers->p->negative = !!($this->registers->a & 0x80);
                $this->registers->p->zero = !$this->registers->a;
                break;
            case 'LDX':
                $this->registers->x = ($mode == Addressing::Immediate) ? $addrOrData : $this->readByte($addrOrData);
                $this->registers->p->negative = !!($this->registers->x & 0x80);
                $this->registers->p->zero = !$this->registers->x;
                break;
            case 'LDY':
                $this->registers->y = ($mode == Addressing::Immediate) ? $addrOrData : $this->readByte($addrOrData);
                $this->registers->p->negative = !!($this->registers->y & 0x80);
                $this->registers->p->zero = !$this->registers->y;
                break;
            case 'STA':
                $this->write($addrOrData, $this->registers->a);
                break;
            case 'STX':
                $this->write($addrOrData, $this->registers->x);
                break;
            case 'STY':
                $this->write($addrOrData, $this->registers->y);
                break;
            case 'TAX':
                $this->registers->x = $this->registers->a;
                $this->registers->p->negative = !!($this->registers->x & 0x80);
                $this->registers->p->zero = !$this->registers->x;
                break;
            case 'TAY':
                $this->registers->y = $this->registers->a;
                $this->registers->p->negative = !!($this->registers->y & 0x80);
                $this->registers->p->zero = !$this->registers->y;
                break;
            case 'TSX':
                $this->registers->x = $this->registers->sp & 0xFF;
                $this->registers->p->negative = !!($this->registers->x & 0x80);
                $this->registers->p->zero = !$this->registers->x;
                break;
            case 'TXA':
                $this->registers->a = $this->registers->x;
                $this->registers->p->negative = !!($this->registers->a & 0x80);
                $this->registers->p->zero = !$this->registers->a;
                break;
            case 'TXS':
                $this->registers->sp = $this->registers->x + 0x0100;
                break;
            case 'TYA':
                $this->registers->a = $this->registers->y;
                $this->registers->p->negative = !!($this->registers->a & 0x80);
                $this->registers->p->zero = !$this->registers->a;
                break;
            case 'ADC':
                $data = ($mode == Addressing::Immediate) ? $addrOrData : $this->readByte($addrOrData);
                $operated = $data + $this->registers->a + $this->registers->p->carry;
                $overflow = (!((($this->registers->a ^ $data) & 0x80) != 0) &&
                    ((($this->registers->a ^ $operated) & 0x80)) != 0);
                $this->registers->p->overflow = $overflow;
                $this->registers->p->carry = $operated > 0xFF;
                $this->registers->p->negative = !!($operated & 0x80);
                $this->registers->p->zero = !($operated & 0xFF);
                $this->registers->a = $operated & 0xFF;
                break;
            case 'AND':
                $data = ($mode == Addressing::Immediate) ? $addrOrData : $this->readByte($addrOrData);
                $operated = $data & $this->registers->a;
                $this->registers->p->negative = !!($operated & 0x80);
                $this->registers->p->zero = !$operated;
                $this->registers->a = $operated & 0xFF;
                break;
            case 'ASL':
                if ($mode == Addressing::Accumulator) {
                    $acc = $this->registers->a;
                    $this->registers->p->carry = !!($acc & 0x80);
                    $this->registers->a = ($acc << 1) & 0xFF;
                    $this->registers->p->zero = !$this->registers->a;
                    $this->registers->p->negative = !!($this->registers->a & 0x80);
                } else {
                    $data = $this->readByte($addrOrData);
                    $this->registers->p->carry = !!($data & 0x80);
                    $shifted = ($data << 1) & 0xFF;
                    $this->write($addrOrData, $shifted);
                    $this->registers->p->zero = !$shifted;
                    $this->registers->p->negative = !!($shifted & 0x80);
                }
                break;
            case 'BIT':
                $data = $this->readByte($addrOrData);
                $this->registers->p->negative = !!($data & 0x80);
                $this->registers->p->overflow = !!($data & 0x40);
                $this->registers->p->zero = !($this->registers->a & $data);
                break;
            case 'CMP':
                $data = ($mode == Addressing::Immediate) ? $addrOrData : $this->readByte($addrOrData);
                $compared = $this->registers->a - $data;
                $this->registers->p->carry = $compared >= 0;
                $this->registers->p->negative = !!($compared & 0x80);
                $this->registers->p->zero = !($compared & 0xff);
                break;
            case 'CPX':
                $data = ($mode == Addressing::Immediate) ? $addrOrData : $this->readByte($addrOrData);
                $compared = $this->registers->x - $data;
                $this->registers->p->carry = $compared >= 0;
                $this->registers->p->negative = !!($compared & 0x80);
                $this->registers->p->zero = !($compared & 0xff);
                break;
            case 'CPY':
                $data = ($mode == Addressing::Immediate) ? $addrOrData : $this->readByte($addrOrData);
                $compared = $this->registers->y - $data;
                $this->registers->p->carry = $compared >= 0;
                $this->registers->p->negative = !!($compared & 0x80);
                $this->registers->p->zero = !($compared & 0xff);
                break;
            case 'DEC':
                $data = ($this->readByte($addrOrData) - 1) & 0xFF;
                $this->registers->p->negative = !!($data & 0x80);
                $this->registers->p->zero = !$data;
                $this->write($addrOrData, $data);
                break;
            case 'DEX':
                $this->registers->x = ($this->registers->x - 1) & 0xFF;
                $this->registers->p->negative = !!($this->registers->x & 0x80);
                $this->registers->p->zero = !$this->registers->x;
                break;
            case 'DEY':
                $this->registers->y = ($this->registers->y - 1) & 0xFF;
                $this->registers->p->negative = !!($this->registers->y & 0x80);
                $this->registers->p->zero = !$this->registers->y;
                break;
            case 'EOR':
                $data = ($mode == Addressing::Immediate) ? $addrOrData : $this->readByte($addrOrData);
                $operated = $data ^ $this->registers->a;
                $this->registers->p->negative = !!($operated & 0x80);
                $this->registers->p->zero = !$operated;
                $this->registers->a = $operated & 0xFF;
                break;
            case 'INC':
                $data = ($this->readByte($addrOrData) + 1) & 0xFF;
                $this->registers->p->negative = !!($data & 0x80);
                $this->registers->p->zero = !$data;
                $this->write($addrOrData, $data);
                break;
            case 'INX':
                $this->registers->x = ($this->registers->x + 1) & 0xFF;
                $this->registers->p->negative = !!($this->registers->x & 0x80);
                $this->registers->p->zero = !$this->registers->x;
                break;
            case 'INY':
                $this->registers->y = ($this->registers->y + 1) & 0xFF;
                $this->registers->p->negative = !!($this->registers->y & 0x80);
                $this->registers->p->zero = !$this->registers->y;
                break;
            case 'LSR':
                if ($mode == Addressing::Accumulator) {
                    $acc = $this->registers->a & 0xFF;
                    $this->registers->p->carry = !!($acc & 0x01);
                    $this->registers->a = $acc >> 1;
                    $this->registers->p->zero = !$this->registers->a;
                } else {
                    $data = $this->readByte($addrOrData);
                    $this->registers->p->carry = !!($data & 0x01);
                    $this->registers->p->zero = !($data >> 1);
                    $this->write($addrOrData, $data >> 1);
                }
                $this->registers->p->negative = false;
                break;
            case 'ORA':
                $data = ($mode == Addressing::Immediate) ? $addrOrData : $this->readByte($addrOrData);
                $operated = $data | $this->registers->a;
                $this->registers->p->negative = !!($operated & 0x80);
                $this->registers->p->zero = !$operated;
                $this->registers->a = $operated & 0xFF;
                break;
            case 'ROL':
                if ($mode == Addressing::Accumulator) {
                    $acc = $this->registers->a;
                    $this->registers->a = ($acc << 1) & 0xFF | ($this->registers->p->carry ? 0x01 : 0x00);
                    $this->registers->p->carry = !!($acc & 0x80);
                    $this->registers->p->zero = !$this->registers->a;
                    $this->registers->p->negative = !!($this->registers->a & 0x80);
                } else {
                    $data = $this->readByte($addrOrData);
                    $writeData = ($data << 1 | ($this->registers->p->carry ? 0x01 : 0x00)) & 0xFF;
                    $this->write($addrOrData, $writeData);
                    $this->registers->p->carry = !!($data & 0x80);
                    $this->registers->p->zero = !$writeData;
                    $this->registers->p->negative = !!($writeData & 0x80);
                }
                break;
            case 'ROR':
                if ($mode == Addressing::Accumulator) {
                    $acc = $this->registers->a;
                    $this->registers->a = $acc >> 1 | ($this->registers->p->carry ? 0x80 : 0x00);
                    $this->registers->p->carry = !!($acc & 0x01);
                    $this->registers->p->zero = !$this->registers->a;
                    $this->registers->p->negative = !!($this->registers->a & 0x80);
                } else {
                    $data = $this->readByte($addrOrData);
                    $writeData = $data >> 1 | ($this->registers->p->carry ? 0x80 : 0x00);
                    $this->write($addrOrData, $writeData);
                    $this->registers->p->carry = !!($data & 0x01);
                    $this->registers->p->zero = !$writeData;
                    $this->registers->p->negative = !!($writeData & 0x80);
                }
                break;
            case 'SBC':
                $data = ($mode == Addressing::Immediate) ? $addrOrData : $this->readByte($addrOrData);
                $operated = $this->registers->a - $data - ($this->registers->p->carry ? 0 : 1);
                $overflow = ((($this->registers->a ^ $operated) & 0x80) != 0 &&
                    (($this->registers->a ^ $data) & 0x80) != 0);
                $this->registers->p->overflow = $overflow;
                $this->registers->p->carry = $operated >= 0;
                $this->registers->p->negative = !!($operated & 0x80);
                $this->registers->p->zero = !($operated & 0xFF);
                $this->registers->a = $operated & 0xFF;
                break;
            case 'PHA':
                $this->push($this->registers->a);
                break;
            case 'PHP':
                $this->registers->p->break_mode = true;
                $this->pushStatus();
                break;
            case 'PLA':
                $this->registers->a = $this->pop();
                $this->registers->p->negative = !!($this->registers->a & 0x80);
                $this->registers->p->zero = !$this->registers->a;
                break;
            case 'PLP':
                $this->popStatus();
                $this->registers->p->reserved = true;
                break;
            case 'JMP':
                $this->registers->pc = $addrOrData;
                break;
            case 'JSR':
                $pc = $this->registers->pc - 1;
                $this->push(($pc >> 8) & 0xFF);
                $this->push($pc & 0xFF);
                $this->registers->pc = $addrOrData;
                break;
            case 'RTS':
                $this->popPC();
                $this->registers->pc++;
                break;
            case 'RTI':
                $this->popStatus();
                $this->popPC();
                $this->registers->p->reserved = true;
                break;
            case 'BCC':
                if (!$this->registers->p->carry) {
                    $this->branch($addrOrData);
                }
                break;
            case 'BCS':
                if ($this->registers->p->carry) {
                    $this->branch($addrOrData);
                }
                break;
            case 'BEQ':
                if ($this->registers->p->zero) {
                    $this->branch($addrOrData);
                }
                break;
            case 'BMI':
                if ($this->registers->p->negative) {
                    $this->branch($addrOrData);
                }
                break;
            case 'BNE':
                if (!$this->registers->p->zero) {
                    $this->branch($addrOrData);
                }
                break;
            case 'BPL':
                if (!$this->registers->p->negative) {
                    $this->branch($addrOrData);
                }
                break;
            case 'BVS':
                if ($this->registers->p->overflow) {
                    $this->branch($addrOrData);
                }
                break;
            case 'BVC':
                if (!$this->registers->p->overflow) {
                    $this->branch($addrOrData);
                }
                break;
            case 'CLD':
                $this->registers->p->decimal_mode = false;
                break;
            case 'CLC':
                $this->registers->p->carry = false;
                break;
            case 'CLI':
                $this->registers->p->interrupt = false;
                break;
            case 'CLV':
                $this->registers->p->overflow = false;
                break;
            case 'SEC':
                $this->registers->p->carry = true;
                break;
            case 'SEI':
                $this->registers->p->interrupt = true;
                break;
            case 'SED':
                $this->registers->p->decimal_mode = true;
                break;
            case 'BRK':
                $interrupt = $this->registers->p->interrupt;
                $this->registers->pc++;
                $this->push(($this->registers->pc >> 8) & 0xFF);
                $this->push($this->registers->pc & 0xFF);
                $this->registers->p->break_mode = true;
                $this->pushStatus();
                $this->registers->p->interrupt = true;
                // Ignore interrupt when already set.
                if (!$interrupt) {
                    $this->registers->pc = $this->readWord(0xFFFE);
                }
                $this->registers->pc--;
                break;
            case 'NOP':
                break;
            // Unofficial Opecode
            case 'NOPD':
                $this->registers->pc++;
                break;
            case 'NOPI':
                $this->registers->pc += 2;
                break;
            case 'LAX':
                $this->registers->a = $this->registers->x = $this->readByte($addrOrData);
                $this->registers->p->negative = !!($this->registers->a & 0x80);
                $this->registers->p->zero = !$this->registers->a;
                break;
            case 'SAX':
                $operated = $this->registers->a & $this->registers->x;
                $this->write($addrOrData, $operated);
                break;
            case 'DCP':
                $operated = ($this->readByte($addrOrData) - 1) & 0xFF;
                $this->registers->p->negative = !!((($this->registers->a - $operated) & 0x1FF) & 0x80);
                $this->registers->p->zero = !(($this->registers->a - $operated) & 0x1FF);
                $this->write($addrOrData, $operated);
                break;
            case 'ISB':
                $data = ($this->readByte($addrOrData) + 1) & 0xFF;
                $operated = (~$data & 0xFF) + $this->registers->a + $this->registers->p->carry;
                $overflow = (!((($this->registers->a ^ $data) & 0x80) != 0) &&
                    ((($this->registers->a ^ $operated) & 0x80)) != 0);
                $this->registers->p->overflow = $overflow;
                $this->registers->p->carry = $operated > 0xFF;
                $this->registers->p->negative = !!($operated & 0x80);
                $this->registers->p->zero = !($operated & 0xFF);
                $this->registers->a = $operated & 0xFF;
                $this->write($addrOrData, $data);
                break;
            case 'SLO':
                $data = $this->readByte($addrOrData);
                $this->registers->p->carry = !!($data & 0x80);
                $data = ($data << 1) & 0xFF;
                $this->registers->a |= $data;
                $this->registers->p->negative = !!($this->registers->a & 0x80);
                $this->registers->p->zero = !($this->registers->a & 0xFF);
                $this->write($addrOrData, $data);
                break;
            case 'RLA':
                $data = ($this->readByte($addrOrData) << 1) + $this->registers->p->carry;
                $this->registers->p->carry = !!($data & 0x100);
                $this->registers->a = ($data & $this->registers->a) & 0xFF;
                $this->registers->p->negative = !!($this->registers->a & 0x80);
                $this->registers->p->zero = !($this->registers->a & 0xFF);
                $this->write($addrOrData, $data);
                break;
            case 'SRE':
                $data = $this->readByte($addrOrData);
                $this->registers->p->carry = !!($data & 0x01);
                $data >>= 1;
                $this->registers->a ^= $data;
                $this->registers->p->negative = !!($this->registers->a & 0x80);
                $this->registers->p->zero = !($this->registers->a & 0xFF);
                $this->write($addrOrData, $data);
                break;
            case 'RRA':
                $data = $this->readByte($addrOrData);
                $carry = !!($data & 0x01);
                $data = ($data >> 1) | ($this->registers->p->carry ? 0x80 : 0x00);
                $operated = $data + $this->registers->a + $carry;
                $overflow = (!((($this->registers->a ^ $data) & 0x80) != 0) &&
                    ((($this->registers->a ^ $operated) & 0x80)) != 0);
                $this->registers->p->overflow = $overflow;
                $this->registers->p->negative = !!($operated & 0x80);
                $this->registers->p->zero = !($operated & 0xFF);
                $this->registers->a = $operated & 0xFF;
                $this->registers->p->carry = $operated > 0xFF;
                $this->write($addrOrData, $data);
                break;

            default:
                throw new \Exception(sprintf('Unknown opecode %s detected.', $baseName));
        }
    }

    public function processNmi()
    {
        $this->interrupts->deassertNmi();
        $this->registers->p->break_mode = false;
        $this->push(($this->registers->pc >> 8) & 0xFF);
        $this->push($this->registers->pc & 0xFF);
        $this->pushStatus();
        $this->registers->p->interrupt = true;
        $this->registers->pc = $this->readWord(0xFFFA);
    }

    public function processIrq()
    {
        if ($this->registers->p->interrupt) {
            return;
        }
        $this->interrupts->deassertIrq();
        $this->registers->p->break_mode = false;
        $this->push(($this->registers->pc >> 8) & 0xFF);
        $this->push($this->registers->pc & 0xFF);
        $this->pushStatus();
        $this->registers->p->interrupt = true;
        $this->registers->pc = $this->readWord(0xFFFE);
    }

    /**
     * @return int
     * @throws \Exception
     */
    public function run(): int
    {
        if ($this->interrupts->isNmiAssert()) {
            $this->processNmi();
        }
        if ($this->interrupts->isIrqAssert()) {
            $this->processIrq();
        }
        $opcode = $this->fetchByte($this->registers->pc);
        $ocp = $this->opCodeList[$opcode];
        list($addrOrData, $additionalCycle) = $this->getAddrOrDataWithAdditionalCycle($ocp->mode);
        $this->execInstruction($ocp->baseName, $addrOrData, $ocp->mode);

        return $ocp->cycle + $additionalCycle + ($this->hasBranched ? 1 : 0);
    }

    private function debug($opcode)
    {
        printf(
            "Invalid opcode: %s in pc: %04x\n",
            dechex($opcode),
            $this->registers->pc
        );
        if ($this->registers->pc < 0x0800) {
            Debugger::dump($this->bus->ram->ram);
        } else {
            if ($this->registers->pc < 0x2000) {
                printf("Redirect ram: %04x\n", $this->registers->pc - 0x0800);
                Debugger::dump($this->bus->ram->ram);
            } else {
                if ($this->registers->pc < 0x4000) {
                    printf("Ppu: %04x\n", ($this->registers->pc - 0x2000) % 8);
                    Debugger::dump($this->bus->ppu->registers);
                } else {
                    if ($this->registers->pc === 0x4016) {
                        printf("Keypad\n");
                    } else {
                        if ($this->registers->pc >= 0xC000) {
                            if ($this->bus->programRom->size() <= 0x4000) {
                                printf("Redirect program rom: %04x\n", $this->registers->pc - 0xC000);
                                Debugger::dump($this->bus->programRom->rom);
                            } else {
                                printf("Redirect program rom: %04x\n", $this->registers->pc - 0x8000);
                                Debugger::dump($this->bus->programRom->rom);
                            }
                        } else {
                            if ($this->registers->pc >= 0x8000) {
                                printf("Redirect program rom: %04x\n", $this->registers->pc - 0x8000);
                                Debugger::dump($this->bus->programRom->rom);
                            } else {
                                printf("Something wrong...\n");
                            }
                        }
                    }
                }
            }
        }
    }
}
