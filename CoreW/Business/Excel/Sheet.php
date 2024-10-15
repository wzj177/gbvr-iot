<?php


namespace CoreW\Business\Excel;


use PhpOffice\PhpSpreadsheet\Style\Alignment as PHPExcel_Style_Alignment;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 *
 * @link  https://cloud.tencent.com/developer/article/1990224
 * Class Sheet
 * @package Biz\Excel
 */
class Sheet
{
    public $header;
    public $title;
    public $defaultStyle = ['titleSize' => 14, 'bodySize' => 12, 'columnWidth' => 40];
    public $column = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'AA', 'AB', 'AC', 'AD', 'AE', 'AF', 'AG', 'AH', 'AI', 'AJ', 'AK', 'AL', 'AM', 'AN', 'AO', 'AP', 'AQ', 'AR', 'AS', 'AT', 'AU', 'AV', 'AW', 'AX', 'AY', 'AZ'];
    public $notes;
    public $data;
    public $sIndex = 1;
    private $spreadsheet;
    public $columnNumber;

    public function __construct(Spreadsheet $spreadsheet, $workSheetIndex = 0)
    {
        $this->spreadsheet = $spreadsheet;
        if ($workSheetIndex > 0) {
            $spreadsheet->createSheet();
        }
        $spreadsheet->setActiveSheetIndex($workSheetIndex);
    }

    public function getSpreadsheet(): Spreadsheet
    {
        return $this->spreadsheet;
    }

    public function getWorkSheet(): Worksheet
    {
        return $this->getSpreadsheet()->getActiveSheet();
    }

    /**
     * @param $header
     */
    public function setHeader($header)
    {
        if (is_array($header)) {
            $this->header = $header;
        } elseif ($header instanceof \Closure) {
            $this->header = call_user_func($header);
        }
        $this->columnNumber = $this->getColumnNumber();
    }

    public function getHeader()
    {
        return $this->header;
    }

    public function setTitle($title)
    {
        $this->title = $title;
    }

    public function getTitle()
    {
        return $this->title;
    }

    public function getColumnNumber()
    {
        return count($this->header);
    }

    /**
     * 添加备注
     * @param $lastIndex
     * @param int $bodySize
     * @param int $num
     */
    protected function addNotes($lastIndex, $bodySize = 16, $num = 2)
    {
        $workSheet = $this->getWorkSheet();
        $style = [
            'font' => [
                'bold' => true,
                'size' => $bodySize,
                'color' => ['argb' => 'FF0000']
            ],
            'alignment' => ['horizontal' => PHPExcel_Style_Alignment::HORIZONTAL_RIGHT]
        ];
        $lastIndex = $lastIndex + $num;
        $workSheet->getStyle('A' . $lastIndex)->applyFromArray($style);
        $workSheet->mergeCells('A' . $lastIndex . ':' . chr(ord('A') + $this->columnNumber - 1) . $lastIndex);
        $workSheet->setCellValue('A' . $lastIndex, $this->notes);
    }

    public function generateTitle()
    {
        if (!empty($this->title)) {
            $styleArray1 = [
                'font' => [
                    'bold' => false,
                    'size' => $this->defaultStyle['titleSize'],
                    'color' => [
                        'argb' => '00000000',
                    ],
                ],
                'alignment' => [
                    'horizontal' => PHPExcel_Style_Alignment::HORIZONTAL_CENTER,
                ],
            ];
            $this->getWorkSheet()->setCellValue('A' . $this->sIndex, $this->title);
            $this->getWorkSheet()->getStyle('A' . $this->sIndex)->applyFromArray($styleArray1);
            $ei = $this->computeExcelCellWord($this->columnNumber - 1);
            $this->getWorkSheet()->mergeCells('A' . $this->sIndex . ':' . $ei . $this->sIndex);//合并标题
        }
    }

    /**
     * @param int $sIndex
     * @param $header
     */
    public function generateHead($sIndex = 1, $header)
    {
        $this->setHeader($header);
        $this->sIndex = $sIndex;
        if (!empty($this->title)) {
            $this->sIndex += 1;
        }
        $this->generateTitle();
        foreach ($this->header as $key => $value) {
            $this->getWorkSheet()
                ->getColumnDimension($this->column[$key]);
        }
    }

    public function generateBody($data = [], $sIndex)
    {
        $this->getWorkSheet()->fromArray($data, NULL, 'A' . $sIndex);
    }

    public function generate($sIndex, $header, $body = [])
    {
        $array = array_merge([$header], $body);
        $this->generateHead($sIndex, $header);
        $this->getWorkSheet()->fromArray($array, NULL, 'A' . $this->sIndex);
        foreach ($this->getWorkSheet()->getColumnDimensions() as $columnDimension) {
            $columnDimension->setAutoSize(true);
        }
    }

    /**
     * 求excel列的值
     * @example  A AA BA CA
     * @param $col 数字列
     * @return string
     */
    protected function computeExcelCellWord($col)
    {
        if ($col <= 25) {
            return chr(ord('A') + $col);
        }

        $c = (int)floor($col / 26);
        $cellBIndex = $col - 26 * $c;
        $cellB = chr(ord('A') + $c - 1) . chr(ord('A') + $cellBIndex);
        return $cellB;
    }
}