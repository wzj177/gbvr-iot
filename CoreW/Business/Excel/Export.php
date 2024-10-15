<?php


namespace CoreW\Business\Excel;


use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as Wxlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment as PHPExcel_Style_Alignment;


class Export
{
    // 导出文件名
    public $filename;
    // 是否保存到服务器
    public $isSave = false;
    private $spreadsheet;
    private $writer;

    /**
     * ExportExcelHelper constructor.
     * @param $filename
     * @param $header
     * @param string $title
     * @param bool $isSave
     * @param bool $isMaxShow
     * @param string $notes
     */
    public function __construct($filename, $isSave = false)
    {
        $this->filename = $filename;
        $this->isSave = $isSave;
        $this->init();
    }

    /**
     * 初始化插件
     */
    private function init()
    {
        $this->setSpreadsheet(new Spreadsheet());
        $this->setWxlsx();
    }

    /**
     * @param ?Spreadsheet $Spreadsheet
     */
    public function setSpreadsheet(?Spreadsheet $Spreadsheet)
    {
        $this->spreadsheet = $Spreadsheet;
    }

    /**
     * @return Spreadsheet
     */
    public function getSpreadsheet(): Spreadsheet
    {
        return $this->spreadsheet;
    }

    /**
     * 设置writer
     */
    public function setWxlsx()
    {
        $this->writer = new Wxlsx($this->spreadsheet);
    }

    public function getWxlsx(): Wxlsx
    {
        return $this->writer;
    }

    /**
     * 保存
     * @param null $downloadName
     * @return bool|\support\Response
     * @throws \PhpOffice\PhpSpreadsheet\Writer\Exception
     */
    protected function saveFile($downloadName = null)
    {
        !$downloadName && $downloadName = time();
        $this->getWxlsx()->save($this->filename);
        $this->setSpreadsheet(null);
        if (function_exists('response')) {
            return response()->download($this->filename, $downloadName . '.xlsx');
        }
        return true;
    }


    /**
     * 原生输出流
     * @throws \PhpOffice\PhpSpreadsheet\Writer\Exception
     */
    protected function nativeOutputStream()
    {
        $contentDispositionField = sprintf('Content-Disposition: attachment; filename="%s"', $this->filename);
        $mime = 'application/vnd.ms-excel;charset=UTF-8';
        ob_end_clean();
        header('Pragma: public'); // required
        header('Expires: 0'); // no cache
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Cache-Control: private', false);
        header('Cache-Control: max-age=0');
        header('Content-Type: ' . $mime);
        header($contentDispositionField);
        header('Content-Transfer-Encoding: binary');
        header('Connection: close');
        $this->getWxlsx()->save('php://output');
        $this->getSpreadsheet()->disconnectWorksheets();
        $this->setSpreadsheet(null);
        exit(0);
    }

    /**
     * webman cli 方式输出流
     */
    protected function cliOutStream()
    {
        $response = response();
        ob_start();
        $this->getWxlsx()->save('php://output');
        $this->getSpreadsheet()->disconnectWorksheets();
        $this->setSpreadsheet(null);
        $content = ob_get_contents();
        ob_flush();
        flush();
        $response->withHeaders([
            'Content-Type' => 'application/vnd.ms-excel;charset=UTF-8',
            'Content-Disposition' => sprintf("attachment; filename='%s'", $this->filename),
            'Cache-Control' => 'max-age=0',
        ])->withBody($content);

        return $response;
    }

    public function centerTable()
    {
        $this->getSpreadsheet()
            ->getDefaultStyle()
            ->getAlignment()
            ->setHorizontal(PHPExcel_Style_Alignment::HORIZONTAL_CENTER);// 水平居中
        $this->getSpreadsheet()
            ->getDefaultStyle()
            ->getAlignment()
            ->setVertical(PHPExcel_Style_Alignment::VERTICAL_CENTER);// 上下居中
    }

    public function createSheet($workSheetIndex = 0)
    {
        return new Sheet($this->spreadsheet, $workSheetIndex);
    }

    /**
     * 下载名称
     *
     * @param null $downloadName
     * @return bool|\support\Response
     * @throws \PhpOffice\PhpSpreadsheet\Writer\Exception
     */
    public function createExcel($downloadName = null)
    {
        if ($this->isSave) {
            return $this->saveFile($downloadName);
        }

        if (function_exists('response')) {
            return $this->cliOutStream();
        }
        $this->nativeOutputStream();
    }
}