<?php
namespace App\ExportsExcel;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\WithDrawings;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

class ExcelCashierExpenses implements FromView, WithDrawings
{
    use Exportable;
    public function __construct($data){
        $this->data = $data;
    }

    public function view(): View
    {
         return view('reports.excel.cashierExpenses', ["request"=>$this->data]);
    }

    public function drawings()
    {
        $drawing = new Drawing();
        $drawing->setName('Logo Mypos');
        $drawing->setDescription('Logo Mypos');
        $drawing->setPath(public_path('/images/logo.png'));
        $drawing->setHeight(110);
        $drawing->setCoordinates('A1');

        return $drawing;
    }
}