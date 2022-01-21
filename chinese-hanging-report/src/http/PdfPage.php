<?php

namespace MyCustomNamespace\http;

use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\Exceptions\HttpNotFoundException;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Module\ModuleReportInterface;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\ModuleService;
use Fisharebest\Webtrees\Tree;
use MyCustomNamespace\ChineseHangingModule;
use MyCustomNamespace\Functions;
use MyCustomNamespace\IndividualExt;
use phpDocumentor\Reflection\Types\Self_;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;
use SplFileObject;
use TCPDF;


class PdfPage implements RequestHandlerInterface
{
    //php script execution timeout setting
    public const MAX_EXECUTION_TIME = '6000';

    public const ROUTE_PREFIX = 'chinese-hanging-data';

    public const NEW_BRANCH = 'new_branch';

    public const EXT_BOT_QUE = 'ext_bot_que';

    public const CHILDREN = 'children';

    public const SIBLINGS = 'siblings';

    public const STAND_BY = 'stand_by';

//    public const NORM = 'norm';

    protected $module_service;

    protected $tree;

    //current left most $next
    protected $cur_r_x =[];

    protected $pdf;

    //this font does support Chinese characters and doesn't look bad
    protected $font = "stsongstdlight";
    //default font size 15pt
    protected $fontSize = 15;

    protected $pageSize = 'A4';
    //default A4 width in pt
    protected $pageWidth = 595.28;
    //default A4 height in pt
    protected $pageHeight = 841.89;

    protected $pageMargin = 60;
    //page internal margin
    protected $pageInMargin = 15;

    protected $pageHeader = '';

    protected $pageFooter = '';

    protected $colors = '';
    
    protected $curOp = self::STAND_BY;

    protected $curPageNum = 0;

    protected $maxPageNum = 0;

    protected $resumeArr = [];


    /**
     * ReportEngineController constructor.
     *
     * @param ModuleService $module_service
     */
    public function __construct(ModuleService $module_service)
    {
        $this->module_service = $module_service;
    }

    public function handle(Request $request): ResponseInterface
    {
            switch ($request->getAttribute('action')) {
                case 'reportPDF':
                    return $this->reportPDF($request);
                default:
                    throw new HttpNotFoundException();
            }

    }

    public function reportPDF(ServerRequestInterface $request): ResponseInterface
    {
//        ini_set('max_execution_time', self::MAX_EXECUTION_TIME);
        set_time_limit(self::MAX_EXECUTION_TIME);
        $tree = $request->getAttribute('tree');
        $this->tree = $tree;
        assert($tree instanceof Tree);
        $user = $request->getAttribute('user');
        assert($user instanceof UserInterface);
        Auth::checkComponentAccess(app(ChineseHangingModule::class), ModuleReportInterface::class, $tree, $user);
        $params = $request->getParsedBody();
        $xrefGens  = $params['xrefGens'] ?? [];
        $vars  = $params['vars'] ?? [];
        $genea_all = $vars['genea_all']??'';
        $resume = $vars['resume']??'';
        $maxgen = $vars['maxgen']??'';
        $relatives = $vars['relatives']??'';
        $photos = $vars['photos']??'';
        $date = $vars['date']??'';
        $caste = $vars['caste']??'';
        $occu = $vars['occu']??'';
        $resi = $vars['resi']??'';
        $spou_date = $vars['spou_date']??'';
        $header = $vars['header']??'';
        $footer = $vars['margin']??'';
        $pageSize = $vars['pageSize']??'';
        $this->colors = $vars['colors']??'';
        $this->pdfInit($pageSize,$header,$footer);
        $format      = $params['format'] ?? 'PDF';
        $destination = $params['destination'] ?? 'view';
        $user->setPreference('default-report-destination', $destination);
        $user->setPreference('default-report-format', $format);

        if ($genea_all==='on'){
            foreach ($xrefGens as $xref => $gen){
                Registry::cache()->file()->forget("generation-".$xref);
                Registry::cache()->file()->remember("generation-".$xref,function () use($gen) :string{return $gen;});
            }
        }
        ob_start();
        if ($genea_all==='on'){
            $this->geneaAllPDF($xrefGens);
        }
        else{
            //all generations,assume no one's generation can be greater than 999999
            if ($maxgen == -1){
                $maxgen = 999999;
            }
            $xrefGen[current($params['xrefs'])] = current($xrefGens);
            $xGsReformed = Functions::getIndiBranches($xrefGen,$maxgen,$tree);
            $this->geneaIndiPDF( $xGsReformed,$xrefGen,$maxgen,$relatives);
        }
        if ($resume === 'on'){
            $this->resumePDF($photos,$date,$caste,$occu,$resi,$spou_date);
        }

        echo $this->pdf->Output('doc.pdf', 'S');
        $this->pdf = ob_get_clean();

        $headers = ['Content-Type' => 'application/pdf'];

        if ($destination === 'download') {
            $headers['Content-Disposition'] = 'attachment; filename="' . addcslashes(PdfPage::ROUTE_PREFIX, '"') . '.pdf"';
        }

        return response($this->pdf, StatusCodeInterface::STATUS_OK, $headers);
    }


    public function geneaAllPDF($xrefGens):void{
        $pdf = $this->pdf;
        arsort($xrefGens);
        $genStart = end($xrefGens);
        $currIndi = key($xrefGens);
        array_pop($xrefGens);
        $branchStack = $xrefGens;
        $curGen = $genStart;
        $pageDownLinks = [];
        $xrefDownLinks = [];
        $pageUpLinks = [];
        $xrefUpLinks = [];
        //individuals
        $indiStack =[];
        //individuals at the bottom
        $bottomQueue = [];
        $next = $this->newPage($genStart,[],[$currIndi=>$curGen],1,$indiStack);
        $this->resumeArr[$curGen] []= $currIndi;
        $isGivUp = false;
        $this->curOp = self::STAND_BY;
        while (1){
            switch ($this->curOp){
                case self::NEW_BRANCH :
                    $next['shift']['left'] = $this->cur_r_x['shift']['left'];
                    $next['left']['point'] = $this->cur_r_x['left']['point'];
                    if ($genStart<=$curGen && $curGen<=$genStart+7){
                        $next['prev']['y'] = ($curGen-$genStart)*88;
                        $next = $this->placeLeftIndi($next,[$currIndi=>$curGen],$curGen-$genStart+1,false,$genStart,$indiStack,false);
                    }
                    else{
                        $next['prev']['y'] = 0;
                        $next = $this->placeLeftIndi($next,[$currIndi=>$curGen],1,false,$curGen,$indiStack,true);
                        $genStart = $curGen;
                    }
                    $this->cur_r_x = $next;
                    break;
                case self::EXT_BOT_QUE :
                    $next['shift']['left'] = $this->cur_r_x['shift']['left'];
                    $next['left']['point'] = $this->cur_r_x['left']['point'];
                    $next['prev']['y'] = 0;
                    if ($curGen===$genStart){
                        $next = $this->placeLeftIndi($next,[$currIndi=>$curGen],1,false,$curGen,$indiStack,false);
                    }
                    else{
                        $next = $this->placeLeftIndi($next,[$currIndi=>$curGen],1,false,$curGen,$indiStack,true);
                        $genStart = $curGen;
                    }
                    $link = $pdf->AddLink();
                    $pageUpLinks[$this->curPageNum] []= ['xref'=>$currIndi,'link'=>$link,'x'=>$next['top']['x']];
                    $xrefUpLinks[$currIndi] = ['pageNum'=>$this->curPageNum,'link'=>$link,'x'=>$next['top']['x']];
                    $this->cur_r_x = $next;
                    break;
                case self::CHILDREN :
                    $next = $this->placeDownIndi($next,[$currIndi=>$curGen],$curGen-$genStart+1,$genStart,$indiStack);
                    if ($next['shift']['left']<$this->cur_r_x['shift']['left']){
                        $this->cur_r_x = $next;
                    }
                    if ($curGen-$genStart === 7){
                        $link = $pdf->AddLink();
                        $pageDownLinks[$this->curPageNum] []= ['xref'=>$currIndi,'link'=>$link,'x'=>$next['top']['x']];
                        $xrefDownLinks[$currIndi] = ['pageNum'=>$this->curPageNum,'link'=>$link,'x'=>$next['top']['x']];
                    }
                    if (!empty($indiStack[$curGen])){
                        $indiStack[$curGen]['next'] = $next;
                    }
                    break;
                case self::SIBLINGS :
                    if ($curGen-$genStart === 7){
                        $next = $this->placeLeftIndi($next,[$currIndi=>$curGen],$curGen-$genStart+1,true,$genStart,$indiStack,false);
                        $link = $pdf->AddLink();
                        $pageDownLinks[$this->curPageNum] []= ['xref'=>$currIndi,'link'=>$link,'x'=>$next['top']['x']];
                        $xrefDownLinks[$currIndi] = ['pageNum'=>$this->curPageNum,'link'=>$link,'x'=>$next['top']['x']];
                    }
                    else{
                        $next['shift']['left'] = $this->cur_r_x['shift']['left'];
                        $next['left']['point'] = $this->cur_r_x['left']['point'];
                        $next = $this->placeLeftIndi($next,[$currIndi=>$curGen],$curGen-$genStart+1,true,$genStart,$indiStack,false);
                    }
                    if ($next['shift']['left']<$this->cur_r_x['shift']['left']){
                        $this->cur_r_x = $next;
                    }
                    if (!empty($indiStack[$curGen])){
                        $indiStack[$curGen]['next'] = $next;
                    }
                    break;
                default: break;
            }

            //instack or inqueue
            if (!$isGivUp){
                if ($curGen-$genStart <> 7){
                    $currIndiExt = new IndividualExt($currIndi,$this->tree);
                    foreach ($currIndiExt->chilXrefTypes() as $chilXT){
                        $indiStack[$curGen+1]['indis'] []= $chilXT;
                        if (!$chilXT['isGivUp']){
                            $this->resumeArr[$curGen+1] []= $chilXT['xref'];
                        }
                    }
                    if (!empty($currIndiExt->chilXrefTypes())){
                        $indiStack[$curGen+1]['next'] = $next;
                    }
                }
                else{
                    $bottomQueue[$curGen]['indis'] []= ['xref'=>$currIndi,'isGivUp'=>$isGivUp];
                    $bottomQueue[$curGen]['next'] = $next;
                }
            }

            //  outstack or outqueue
            if (!empty($indiStack)) {
                $currStack = end($indiStack);
                $stackGen = key($indiStack);
                if ($stackGen <= $curGen) {
                    $this->curOp = self::SIBLINGS;
                } else {
                    $this->curOp = self::CHILDREN;
                }
                $curGen = $stackGen;
                $currIndi = end($currStack['indis'])['xref'];
                $isGivUp = end($currStack['indis'])['isGivUp'];
                $next = $currStack['next'];
                array_pop($currStack['indis']);
                if (empty($currStack['indis'])) {
                    array_pop($indiStack);
                }
                else{
                    $indiStack[$stackGen] = $currStack;
                }
                continue;
            }
            if (!empty($bottomQueue)) {
                $this->curOp = self::EXT_BOT_QUE;
                $currQueue = reset($bottomQueue);
                $bqGenStart = key($bottomQueue);
                $curGen = $bqGenStart;
                $currIndi = reset($currQueue['indis'])['xref'];
                $isGivUp = reset($currQueue['indis'])['isGivUp'];
                $next = $currQueue['next'];
                $currQueue['indis']=array_slice($currQueue['indis'], 1, NULL, true);
                if (empty($currQueue['indis'])) {
                    $bottomQueue = array_slice($bottomQueue, 1, NULL, true);
                }
                else{
                    $bottomQueue[$bqGenStart] = $currQueue;
                }
                continue;
            }
            if (!empty($branchStack)){
                $this->curOp = self::NEW_BRANCH;
                $brGenStart = end($branchStack);
                $curGen = $brGenStart;
                $currIndi = key($branchStack);
                $this->resumeArr[$curGen] []= $currIndi;
                $isGivUp = false;
                array_pop($branchStack);
                continue;
            }
            break;
        }

        //print page nums and page links
        $pdf->SetFont($this->font, '', 10);
        for($i = 1; $i<=$this->maxPageNum; $i++){
            $pdf->setPage($i);
            $pdf->SetXY($this->pageWidth*0.1,-2*$this->fontSize);
            $pdf->Cell(0,0,'第'.$i.'页 / 共'.$this->maxPageNum.'页');
            if (!empty($pageUpLinks) && !empty($pageUpLinks[$i])){
                foreach ($pageUpLinks[$i] as $item){
                    $curXref = $item['xref'];
                    $pdf->SetXY($item['x']-$this->fontSize*0.8,$this->pageMargin+$this->fontSize*0.1);
                    $pdf->SetLink($xrefDownLinks[$curXref]['link'], $this->pageHeight*0.5, '*'.$xrefDownLinks[$curXref]['pageNum']);
                    $pdf->Cell(30, $this->fontSize, $xrefDownLinks[$curXref]['pageNum'], 0,0,'C',false,$xrefDownLinks[$curXref]['link'],1);
                }
            }
            if (!empty($pageDownLinks) && !empty($pageDownLinks[$i])){
                foreach ($pageDownLinks[$i] as $item){
                    $curXref = $item['xref'];
                    $pdf->SetXY($item['x']-$this->fontSize*0.8,-$this->pageMargin-$this->fontSize);
                    $pdf->SetLink($xrefUpLinks[$curXref]['link'],0, '*'.$xrefUpLinks[$curXref]['pageNum']);
                    $pdf->Cell(30, $this->fontSize, $xrefUpLinks[$curXref]['pageNum'], 0,0,'C',false,$xrefUpLinks[$curXref]['link'],1);
                }
            }
        }

    }


    /**
     * compose individual's genealogy
     * @param $xrefGens
     * @param $type  'ances'  'ancesFam'  'all'
     */
    public function geneaIndiPDF($xrefGens,$xgBase, $maxGen ,$type):void{
        $pdf = $this->pdf;
        $curBackbone = end($xrefGens);
        $xrefFamIndi = end($curBackbone);
        $genStart = reset($xrefFamIndi);
        $currIndi = key($xrefFamIndi);
        $curBackboneXrefs = [] ;
        foreach ($curBackbone as $curBacbonIndi){
            reset($curBacbonIndi);
            $curBackboneXrefs [] = key($curBacbonIndi);
        }
        end($curBackboneXrefs);
        $curGen = $genStart;
        $pageDownLinks = [];
        $xrefDownLinks = [];
        $pageUpLinks = [];
        $xrefUpLinks = [];
        //individuals
        $indiStack =[];
        //individuals at the bottom
        $bottomQueue = [];
        $next = $this->newPage($genStart,[],[$currIndi=>$curGen],1,$indiStack);
        $this->resumeArr[$curGen] []= $currIndi;
        $isGivUp = false;
        $desStartGen = current($xgBase);
        $this->curOp = self::STAND_BY;
        while (1){
            switch ($this->curOp){
                case self::NEW_BRANCH :
                    $next['shift']['left'] = $this->cur_r_x['shift']['left'];
                    $next['left']['point'] = $this->cur_r_x['left']['point'];
                    if ($genStart<=$curGen && $curGen<=$genStart+7){
                        $next['prev']['y'] = ($curGen-$genStart)*88;
                        $next = $this->placeLeftIndi($next,[$currIndi=>$curGen],$curGen-$genStart+1,false,$genStart,$indiStack,false);
                    }
                    else{
                        $next['prev']['y'] = 0;
                        $next = $this->placeLeftIndi($next,[$currIndi=>$curGen],1,false,$curGen,$indiStack,true);
                        $genStart = $curGen;
                    }
                    $this->cur_r_x = $next;
                    break;
                case self::EXT_BOT_QUE :
                    $next['shift']['left'] = $this->cur_r_x['shift']['left'];
                    $next['left']['point'] = $this->cur_r_x['left']['point'];
                    $next['prev']['y'] = 0;
                    $isRemvChildNum = null;
                    if ($type !== 'ances'){
                        if ($desStartGen-$maxGen<$curGen && $curGen<$desStartGen+1){
                            if (!in_array($currIndi,$curBackboneXrefs)){
                                $isRemvChildNum = true;
                            }
                        }
                    }
                    if ($curGen === $desStartGen+$maxGen-1){
                        $isRemvChildNum = true;
                    }
                    if ($curGen===$genStart){
                        $next = $this->placeLeftIndi($next,[$currIndi=>$curGen],1,false,$curGen,$indiStack,false,$isRemvChildNum);
                    }
                    else{
                        $next = $this->placeLeftIndi($next,[$currIndi=>$curGen],1,false,$curGen,$indiStack,true,$isRemvChildNum);
                        $genStart = $curGen;
                    }
                    $link = $pdf->AddLink();
                    $pageUpLinks[$this->curPageNum] []= ['xref'=>$currIndi,'link'=>$link,'x'=>$next['top']['x']];
                    $xrefUpLinks[$currIndi] = ['pageNum'=>$this->curPageNum,'link'=>$link,'x'=>$next['top']['x']];
                    $this->cur_r_x = $next;
                    break;
                case self::CHILDREN :
                    $isRemvChildNum = false;
                    if ($type !=='all' && $curGen == $desStartGen){
                        $isRemvChildNum = true;
                    }
                    if ($curGen===$desStartGen+$maxGen-1){
                        $isRemvChildNum = true;
                    }
                    $next = $this->placeDownIndi($next,[$currIndi=>$curGen],$curGen-$genStart+1,$genStart,$indiStack,$isRemvChildNum);
                    if ($next['shift']['left']<$this->cur_r_x['shift']['left']){
                        $this->cur_r_x = $next;
                    }
                    if ($curGen-$genStart === 7){
                        $link = $pdf->AddLink();
                        $pageDownLinks[$this->curPageNum] []= ['xref'=>$currIndi,'link'=>$link,'x'=>$next['top']['x']];
                        $xrefDownLinks[$currIndi] = ['pageNum'=>$this->curPageNum,'link'=>$link,'x'=>$next['top']['x']];
                    }
                    if (!empty($indiStack[$curGen])){
                        $indiStack[$curGen]['next'] = $next;
                    }
                    break;
                case self::SIBLINGS :
                    if ($curGen-$genStart === 7){
                        $next = $this->placeLeftIndi($next,[$currIndi=>$curGen],$curGen-$genStart+1,true,$genStart,$indiStack,false);
                        $link = $pdf->AddLink();
                        $pageDownLinks[$this->curPageNum] []= ['xref'=>$currIndi,'link'=>$link,'x'=>$next['top']['x']];
                        $xrefDownLinks[$currIndi] = ['pageNum'=>$this->curPageNum,'link'=>$link,'x'=>$next['top']['x']];
                    }
                    else{
//                        if ($descendants && $desStartGen<$curGen && $curGen<$desStartGen+$maxGen-1){
                        if ($desStartGen<$curGen && $curGen<$desStartGen+$maxGen-1){
                            $next['shift']['left'] = $this->cur_r_x['shift']['left'];
                            $next['left']['point'] = $this->cur_r_x['left']['point'];
                        }
                        $isRemvChildNum = null;
                        if ($type !== 'ances'){
                            if ($desStartGen-$maxGen<$curGen && $curGen<$desStartGen+1){
                                if (!in_array($currIndi,$curBackboneXrefs)){
                                    $isRemvChildNum = true;
                                }
                            }
                        }
                        if ($curGen === $desStartGen+$maxGen-1){
                            $isRemvChildNum = true;
                        }

                        $next = $this->placeLeftIndi($next,[$currIndi=>$curGen],$curGen-$genStart+1,true,$genStart,$indiStack,false,$isRemvChildNum);
                    }
                    if ($next['shift']['left']<$this->cur_r_x['shift']['left']){
                        $this->cur_r_x = $next;
                    }
                    if (!empty($indiStack[$curGen])){
                        $indiStack[$curGen]['next'] = $next;
                    }
                    break;
                default: break;
            }

            //instack or inqueue
            if (!$isGivUp){
                if ($curGen-$genStart <> 7){
                    switch ($type){
                        case 'ances':
                            if (!empty(key($curBackbone)) && key($curBackbone)!==0){
                                $child = prev($curBackbone);
                                reset($child);
                                $indiStack[$curGen+1]['indis'] []= ['xref'=>key($child),'isGivUp'=>Functions::isGivenUp($currIndi,key($child),$this->tree)];
                                $indiStack[$curGen+1]['next'] = $next;
                                $this->resumeArr[$curGen+1] []= key($child);
                            }
                            break;
                        case 'ancesFam':
                            if (!empty(key($curBackbone)) && key($curBackbone)!==0){
                                if ($currIndi==current($curBackboneXrefs)){
                                    $child = current($curBackbone);
                                    end($child);
                                    $chilFam = Registry::familyFactory()->make(key($child),$this->tree);
                                    if ($chilFam !== null){
                                        foreach (Functions::chilXrefTypes($currIndi,$chilFam->children(),$this->tree) as $chilXT){
                                            $indiStack[$curGen+1]['indis'] []= $chilXT;
                                            $this->resumeArr[$curGen+1] []= $chilXT['xref'];
                                        }
                                        $indiStack[$curGen+1]['next'] = $next;
                                    }
                                    prev($curBackbone);
                                    prev($curBackboneXrefs);
                                }
                            }
                            break;
                        case 'all':
                            if (!empty(key($curBackbone)) && key($curBackbone)!==0){
                                if ($currIndi==current($curBackboneXrefs)){
                                    $child = current($curBackbone);
                                    end($child);
                                    $chilFam = Registry::familyFactory()->make(key($child),$this->tree);
                                    if ($chilFam !== null){
                                        foreach (Functions::chilXrefTypes($currIndi,$chilFam->children(),$this->tree) as $chilXT){
                                            $indiStack[$curGen+1]['indis'] []= $chilXT;
                                            $this->resumeArr[$curGen+1] []= $chilXT['xref'];
                                        }
                                        $indiStack[$curGen+1]['next'] = $next;
                                    }
                                    prev($curBackbone);
                                    prev($curBackboneXrefs);
                                }
                            }
                            if(( $desStartGen<$curGen && $curGen<$desStartGen+$maxGen-1)||($currIndi === key($xgBase) && $curGen == $desStartGen)){
                                $currIndiExt = new IndividualExt($currIndi,$this->tree);
                                foreach ($currIndiExt->chilXrefTypes() as $chilXT){
                                    $indiStack[$curGen+1]['indis'] []= $chilXT;
                                    if (!$chilXT['isGivUp']){
                                        $this->resumeArr[$curGen+1] []= $chilXT['xref'];
                                    }
                                }
                                if (!empty($currIndiExt->chilXrefTypes())){
                                    $indiStack[$curGen+1]['next'] = $next;
                                }
                            }
                            break;
                        default: break;
                    }
                }
                else{
                    $bottomQueue[$curGen]['indis'] []= ['xref'=>$currIndi,'isGivUp'=>$isGivUp];
                    $bottomQueue[$curGen]['next'] = $next;
                }
            }

            //  outstack or outqueue
            if (!empty($indiStack)) {
                $currStack = end($indiStack);
                $stackGen = key($indiStack);
                if ($stackGen <= $curGen) {
                    $this->curOp = self::SIBLINGS;
                } else {
                    $this->curOp = self::CHILDREN;
                }
                $curGen = $stackGen;
                $next = $currStack['next'];
                $inBackbone = false;
                foreach ($currStack['indis'] as $i => $item) {
                    if ($item['xref']==current($curBackboneXrefs)){
                        $currIndi = $currStack['indis'][$i]['xref'];
                        $isGivUp = $currStack['indis'][$i]['isGivUp'];
                        unset($currStack['indis'][$i]);
                        if (key($curBackboneXrefs)!==null){
                            $inBackbone =true;
                        }
                    }
                }
                if (!$inBackbone){
                    $currIndi = end($currStack['indis'])['xref'];
                    $isGivUp = end($currStack['indis'])['isGivUp'];
                    array_pop($currStack['indis']);
                }
                if (empty($currStack['indis'])) {
                    array_pop($indiStack);
                }
                else{
                    $indiStack[$stackGen] = $currStack;
                }
                continue;
            }

            if (!empty($bottomQueue)) {
                $this->curOp = self::EXT_BOT_QUE;
                $currQueue = reset($bottomQueue);
                $bqGenStart = key($bottomQueue);
                $curGen = $bqGenStart;
                $currIndi = reset($currQueue['indis'])['xref'];
                $isGivUp = reset($currQueue['indis'])['isGivUp'];
                $next = $currQueue['next'];
                $currQueue['indis']=array_slice($currQueue['indis'], 1, NULL, true);
                if (empty($currQueue['indis'])) {
                    $bottomQueue = array_slice($bottomQueue, 1, NULL, true);
                }
                else{
                    $bottomQueue[$bqGenStart] = $currQueue;
                }
                continue;
            }

            if (!empty(key($xrefGens)) && key($xrefGens) !== 0){
                $this->curOp = self::NEW_BRANCH;
                prev($xrefGens);
                $curBackbone = current($xrefGens);
                $xrefFamIndi = end($curBackbone);
                $genStart = reset($xrefFamIndi);
                $currIndi = key($xrefFamIndi);
                foreach ($curBackbone as $curBacbonIndi){
                    reset($curBacbonIndi);
                    $curBackboneXrefs [] = key($curBacbonIndi);
                }
                end($curBackboneXrefs);
                $curGen = $genStart;
                $this->resumeArr[$curGen] []= $currIndi;
                $isGivUp = false;
                continue;
            }
            break;
        }

        //print page nums and page links
        $pdf->SetFont($this->font, '', 10);
        for($i = 1; $i<=$this->maxPageNum; $i++){
            $pdf->setPage($i);
            $pdf->SetXY($this->pageWidth*0.1,-2*$this->fontSize);
            $pdf->Cell(0,0,'第'.$i.'页 / 共'.$this->maxPageNum.'页');
            if (!empty($pageUpLinks) && !empty($pageUpLinks[$i])){
                foreach ($pageUpLinks[$i] as $item){
                    $curXref = $item['xref'];
                    $pdf->SetXY($item['x']-$this->fontSize*0.8,$this->pageMargin+$this->fontSize*0.1);
                    $pdf->SetLink($xrefDownLinks[$curXref]['link'], $this->pageHeight*0.5, '*'.$xrefDownLinks[$curXref]['pageNum']);
                    $pdf->Cell(30, $this->fontSize, $xrefDownLinks[$curXref]['pageNum'], 0,0,'C',false,$xrefDownLinks[$curXref]['link'],1);
                }
            }
            if (!empty($pageDownLinks) && !empty($pageDownLinks[$i])){
                foreach ($pageDownLinks[$i] as $item){
                    $curXref = $item['xref'];
                    $pdf->SetXY($item['x']-$this->fontSize*0.8,-$this->pageMargin-$this->fontSize);
                    $pdf->SetLink($xrefUpLinks[$curXref]['link'],0, '*'.$xrefUpLinks[$curXref]['pageNum']);
                    $pdf->Cell(30, $this->fontSize, $xrefUpLinks[$curXref]['pageNum'], 0,0,'C',false,$xrefUpLinks[$curXref]['link'],1);
                }
            }
        }

    }

    protected function resumePDF ($photos,$date,$caste,$occu,$resi,$spou_date):void {
        $pdf = $this->pdf;

        $pdf->SetMargins(50, 50, 50);
        // set auto page breaks
        $pdf->SetAutoPageBreak(TRUE, 50);
        // set image scale factor
        $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
        $this->pdf->setCellHeightRatio(1.2);

        $pdf->AddPage();

        ksort($this->resumeArr);

        $gens = array_keys($this->resumeArr);
//        $gens = array_unique($gens);
        foreach ($gens as $gen){
            $pdf->SetFont('stsongstdlight', 'B', 20);
            //$pdf->writeHTML('<span>第'.Functions::numToCharacter($gen).'世</span>', true, false, true, false, 'C');
            $pdf->Write(0, '第'.Functions::numToCharacter($gen).'世', '', 0, 'C', true, 0, false, false, 0);
            $pdf->SetMargins(50, 50, 50);
            // set auto page breaks
            $pdf->SetAutoPageBreak(TRUE, 50);
            // set image scale factor
            $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
            $this->pdf->setCellHeightRatio(1.2);
            $pdf->SetFont('stsongstdlight', '', 15);
            $html = '&nbsp;&nbsp;<br /><table style="border:1px solid black;" border="1" cellspacing="0" cellpadding="0" >
            <thead>
            <tr nobr="true">
                <th style="width: 16%;text-align: center;font-weight: bold;margin: auto">&nbsp;&nbsp;<br />关系<br /> </th>
                <th style="width: 12%;text-align: center;font-weight: bold;margin: auto">&nbsp;&nbsp;<br />姓名<br /> </th>';

            if($photos){
                $html .=
                    '  <th style="width: 52%;text-align: center;font-weight: bold;margin: auto">&nbsp;&nbsp;<br />简介<br /> </th>
                       <th style="width: 20%;text-align: center;font-weight: bold;margin: auto">&nbsp;&nbsp;<br />照片<br /> </th>';
            }
            else{
                $html .=
                    '  <th style="width: 72%;text-align: center;font-weight: bold;margin: auto">&nbsp;&nbsp;<br />简介<br /> </th>';
            }

            $html .=
            '</tr>
            </thead>
            <tbody>';

            $resumes = $this->resumeArr[$gen];
            $outstandSpouSpXref = null;
            $outstandSpouses = collect();
            for ($i = 0; $i < count($resumes); $i++) {
                $self = new IndividualExt($resumes[$i],$this->tree);
                $html .= '<tr nobr="true">';
                $parXrefs =  Functions::getIndiAnces($resumes[$i],$this->tree);
                $html .= '<td style="width: 16%;text-align: center;vertical-align: middle"><br />';
                if ($outstandSpouses->isEmpty()){
                    foreach ($parXrefs as $parXref=>$isAdopFost){
                        $par = Registry::individualFactory()->make($parXref,$this->tree);
                        if ($par instanceof Individual){
                            if ($isAdopFost){
                                $html .= '<br />'.$par->getAllNames()[0]['fullNN'] . '之继子';
                            }
                            else{
                                if ($self->sex()==='F'){
                                    $html .= '<br />'.$par->getAllNames()[0]['fullNN'] . '之女';
                                }
                                else{
                                    $html .= '<br />'.$par->getAllNames()[0]['fullNN'] . '之子';
                                }
                            }
                        }
                    }
                }
                else{
                    $outstandSpouSp = Registry::individualFactory()->make($outstandSpouSpXref,$this->tree);
                    if ($outstandSpouSp instanceof Individual){
                        $html .= '<br />配'.$outstandSpouSp->getAllNames()[0]['fullNN'];
                    }
                }

                $html .='</td>';
                $html .= '<td style="width: 12%;text-align: center;vertical-align: middle"><br /><br />'. $self->getAllNames()[0]['fullNN'] . '</td>';

                if ($photos === 'on'){
                    $html .= '<td style="width: 52%;text-align: left;vertical-align: middle"><br />';
                }
                else{
                    $html .= '<td style="width: 72%;text-align: left;vertical-align: middle"><br />';
                }
                if (count($self->getAllNames())>1){
                    $html .= '<br />又名：';
                    foreach ($self->getAllNames() as $j=>$name){
                        if ($j!=0){
                            $html .= $name['fullNN'];
                            if ($j!=count($self->getAllNames())-1){
                                $html .='，';
                            }
                        }
                    }
                }
                if ( $date === 'on'){
                    $birthDateTimePlace = $self->birthDateTimePlace();
                    $deathDateTimePlace = $self->deathDateTimePlace();
                    if ($birthDateTimePlace!=null && $deathDateTimePlace!= null){
                        $html .= '<br />'.$birthDateTimePlace.'，'.$deathDateTimePlace ;
                    }
                    else if ($birthDateTimePlace!=null || $deathDateTimePlace!= null){
                        $html .= '<br />'.$birthDateTimePlace.$deathDateTimePlace ;
                    }
                }

                if ($outstandSpouses->isEmpty()){
                    $twoParsChildren = [];
                    foreach ($self->spouses() as $j=>$spouse){
                        if ($j === 0){
                            $html .= '<br />配偶：';
                        }
                        $html .= $spouse->name();
                        if ($j!=count($self->spouses())-1){
                            $html .= '，';
                        }
                        foreach ($spouse->chilXrefs($resumes[$i]) as $tPchild){
                            $twoParsChildren []= $tPchild;
                        }
                    }
                    foreach ($self->spouses(false) as $spouse){
                        if ($spouse->childrenNames($resumes[$i])!=null){
                            $html .= '。与'.$spouse->name().'的子女：';
                            $html .= $spouse->childrenNames($resumes[$i]);
                        }

                    }
                    $oneParChildren = array_diff($self->chilXrefs(),$twoParsChildren);
                    if ($oneParChildren != null){
                        $html .= '<br />子女：';
                        foreach ($oneParChildren as $j=>$oneParChild){
                            $indiOneParChild = Registry::individualFactory()->make($oneParChild,$this->tree);
                            if ($indiOneParChild instanceof Individual){
                                $html .= $indiOneParChild->getAllNames()[0]['fullNN'];
                                if ($j!=count($oneParChildren)-1){
                                    $html .='，';
                                }
                            }
                        }
                    }
                    $outstandSpouses = $self->spouses(true);
                    if ($outstandSpouses->isNotEmpty()){
                        array_splice( $resumes, $i+1, 0, $self->spouXrefs(true)->all() );
                        $outstandSpouSpXref = $resumes[$i];
                    }
                }
                else{
                    if ($self->childrenNames()!=null){
                        $html .= '<br />子女：'.$self->childrenNames();
                    }
                    $outstandSpouses->pop();
                }
                $casteContent = $self->caste();
                if ($caste === 'on' && $casteContent!=null){
                    $html .= '<br />社会地位：'.$casteContent;
                }
                $occupation = $self->occupation();
                if ($occu === 'on' && $occupation!=null){
                    $html .= '<br />工作：'.$occupation;
                }
                $residency = $self->residency();
                if ($resi === 'on'&& $residency!=null){
                    $html .= '<br />居住地：'.$residency;
                }
                $education = $self->education();
                if ($education!=null){
                    $html .= '<br />学历：'.$education;
                }
                $note = $self->note();
                if ($note!=null){
                    $html .= '<br />备注：'.$note;
                }

                $html .= '</td>';
                if ($photos === 'on'  ){
                    if ($self->imageUrl()!=null){
                        if ($this->pageSize==='A4'){
                            $img = '<img width ="120px" height="120px" src="' . $self->imageUrl() . '" >';
                        }
                        if ($this->pageSize==='A3'){
                            $img = '<img width ="250px" height="250px" src="' . $self->imageUrl() . '" >';
                        }
                        $html .= '<td style="width: 20%;text-align: center;vertical-align: middle">'. $img .'</td>';
                    }
                    else{
                        $html .= '<td style="width: 20%;text-align: center;vertical-align: middle"></td>';
                    }
                }
                $html .= '</tr>';
            }
            $html .= '</tbody>
                </table><br /><br />';
            // output the HTML content
            $pdf->writeHTML($html, true, false, true, false, 'C');

        }
    }

    protected function pdfInit($pageSize,$pageHeader, $pageFooter):void{
        if ($pageSize==='A3'){
            $this->pdf = new TCPDF('L', 'pt', 'A3', true, 'UTF-8', false);
        }
        elseif ($pageSize==='A4'){
            $this->pdf = new TCPDF('P', 'pt', 'A4', true, 'UTF-8', false);
        }
        $this->pageSize = $pageSize;
        $this->pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
        $this->pdf->SetAutoPageBreak(false, 0);
        $this->pageWidth=$this->pdf->getPageWidth();
        $this->pageHeight=$this->pdf->getPageHeight();
        $this->pageHeader=$pageHeader;
        $this->pageFooter=$pageFooter;
        // set document information
        $this->pdf->SetCreator(PDF_CREATOR);
        $this->pdf->SetTitle('Hanging Genealogy');
        // disable header and footer
        $this->pdf->setPrintHeader(false);
        $this->pdf->setPrintFooter(false);
        $this->pdf->SetFont('stsongstdlight', '', 15);
        $this->pdf->setCellPaddings(0, 2, 0,2);
        $this->pdf->setCellHeightRatio(1);
        $this->pdf->setCellMargins(0, 1, 0, 0);
    }


    protected function newPage($genStart, $next ,$xrefGen,$pos,&$indiStack,$remvChildNum=null): array
    {
        $pdf = $this->pdf;
        $style = ['width' => 3, 'cap' => 'butt', 'join' => 'miter', 'dash' => 0, 'color' => array(0, 0, 0)];
        $fontSize = $this->fontSize;
        $h = $this->pageHeight;
        $w = $this->pageWidth;
        $m = $this->pageMargin;
        foreach ($indiStack as $gen => $value) {
            $this->line($indiStack[$gen]['next']['top']['x'],$indiStack[$gen]['next']['top']['y'],$m,$indiStack[$gen]['next']['top']['y']);
            $indiStack[$gen]['next']['top']['x'] =$w - $m;
        }
        $pdf->AddPage();
        $this->curPageNum = $pdf->getPage();
        if ($this->curPageNum > $this->maxPageNum){
            $this->maxPageNum = $this->curPageNum;
        }
//        $pdf->SetFont($this->font, '', 10);
        $pdf->Rect($m, $m, $w-$m*2, $h-$m*2, 'd', array('all' => $style));
        $pdf->SetFont($this->font, 'B', 18);
        $pdf->MultiCell( 20, 18, $this->pageHeader, 0, 'C', 0, 2, 2*$fontSize, $m-10, true);
        $pdf->MultiCell( 20, 18, $this->pageFooter, 0, 'C', 0, 2, 2*$fontSize, $h*0.65, true);
        $next = $this->generations(['prev'=>['y'=>$next['prev']['y']??0]],$genStart,$indiStack,0);
        $next = $this->individualBlock($next, $xrefGen, $pos,'l', $remvChildNum,0);
        $this->cur_r_x = $next;
        return $next;
    }

    protected function line($x0,$y0,$x1,$y1):void{
        $style = ['width' => 1, 'cap' => 'butt', 'join' => 'miter', 'dash' => 0, 'color' => array(0, 0, 0)];
        $this->pdf->Line($x0,$y0,$x1,$y1, $style);
    }


    /**
     *
     * @param array $next position left by preceding individual block
     * @param array $xrefGen next individual awaiting placement
     * @param $pos
     * @param bool $leftLine whether to print left line
     * @param int $genStart current start generation num
     * @param bool $genLi whether to print generation list
     * @return  array $next
     * @see  individualBlock()
     */
    protected function placeLeftIndi($next,$xrefGen,$pos,$leftLine,$genStart,&$indiStack,$genLi=false,$remvChildNum=null):array{
        $m = $this->pageMargin;
        $w = $this->pageWidth;
        if ($genLi===false){
            if (!($this->individualBlock($next, $xrefGen, $pos,'l', $remvChildNum,1)['left']['edge'] < $m)){
                if ($leftLine){
                    $this->line($next['top']['x'],$next['top']['y'],$next["left"]['point'],$next['top']['y']);
                }
                return $this->individualBlock($next, $xrefGen, $pos,'l',  $remvChildNum,0);
            }
            if ($leftLine){
                $this->line($next['top']['x'],$next['top']['y'],$m,$next['top']['y']);
            }

            $next = $this->newPage($genStart,$next,$xrefGen,$pos,$indiStack,$remvChildNum);
            if ($leftLine){
                $this->line($next['top']['x']+6,$next['top']['y'],$w-$m,$next['top']['y']);
            }
            return $next;
        }


        if (!($this->individualBlock($this->generations($next,$genStart,$indiStack,1), $xrefGen, $pos,'l',  $remvChildNum,1)['left']['edge'] < $m)){
            $next = $this->generations($next,$genStart,$indiStack,0);
            return $this->individualBlock($next, $xrefGen, $pos,'l',  $remvChildNum,0);
        }
        return $this->newPage($genStart,$next,$xrefGen,$pos,$indiStack,$remvChildNum);
    }

    protected function placeDownIndi($next,$xrefGen,$pos,$genStart,&$indiStack,$remvChildNum=null):array{
        $m = $this->pageMargin;
        $w = $this->pageWidth;
        $this->line($next['bottom']['x'],$next['bottom']['y'],$next["bottom"]['x'],$next['top']['y']+85);
        if (!($this->individualBlock($next, $xrefGen, $pos, 'd',$remvChildNum,1)['left']['edge'] < $m)){
            return $this->individualBlock($next, $xrefGen, $pos, 'd',$remvChildNum,0);
        }
        //when placing downward blocks but finding no place, need to turn on a new page
        $next = $this->individualBlock($next, $xrefGen, $pos, 'd',$remvChildNum,1);
        $this->line($next['top']['x']+3,$next['top']['y']-3,$m,$next['top']['y']-3);
        //this is to ensure the cross page lines do coincide as this line will also be drawn by newPage function
        if (!empty($indiStack[$genStart+$pos-1])){
            $indiStack[$genStart+$pos-1]['next']['top']['x'] = $next['top']['x']+3;
            $indiStack[$genStart+$pos-1]['next']['top']['y'] = $next['top']['y']-3;
        }

        $next = $this->newPage($genStart,$next,$xrefGen,$pos,$indiStack,$remvChildNum);
        $this->line($next['top']['x']+6,$next['top']['y'],$w-$m,$next['top']['y']);
        return $next;
    }

    protected function triangle($x,$y,$angle):void{
        $style = ['width' => 0, 'cap' => 'butt', 'join' => 'miter', 'dash' => 0, 'color' => array(0, 0, 0)];
        $this->pdf->RegularPolygon($x, $y, 3, 3, $angle, false, 'DF',array('all' => $style), array(0, 0, 0));
    }

    protected function circle($x,$y):void{
        $style5 = ['width' => 1,'cap' => 'butt', 'join' => 'miter','dash' => 0, 'color' => array(0, 0, 0)];
        $this->pdf->Circle($x,$y,3,0,360, 'D',$style5);
    }

    /**
     * @param $next
     * @param int $genNum starting generation number
     * @param bool $measure if true, don't print anything, instead we try to get the space it occupies
     * @return array[]
     */
    protected function generations($next,$genNum,&$indiStack,$measure=false): array{
        $r_x = $next['shift']['left']?? 0;
        $pdf = $this->pdf;
        $fontSize = $this->fontSize;
        $h = $this->pageHeight;
        $w = $this->pageWidth;
        $m = $this->pageMargin;
        $in_m = $this->pageInMargin;
        $pdf->SetFont($this->font, 'B', 12);
        $pdf->setCellPaddings(0, 4, 0,2);
        $this->pdf->SetTextColor(255,255,255);
        $this->pdf->SetFillColor(0, 0, 0);
        $gAMaxCount = 1;

        for ($i=0;$i<8;$i++){
            $gen = Functions::numToCharacter($i+$genNum);
            if ($i+$genNum<=10){
                $genTxt = '第'.$gen.'世';
            }
            else{
                $genTxt = $gen . '世' ;
            }
            $genArr = mb_str_split($genTxt,5);
            if (count($genArr) > $gAMaxCount){
                $gAMaxCount = count($genArr);
            }

            if (mb_strlen($genTxt)>5){
                if (!$measure){
                    foreach ($genArr as $j => $jValue) {
                        $this->pdf->MultiCell( 20, 66, $genArr[$j], 0, 'C', 1, 2, $w-$m-(0.8*$j+2)*$fontSize+$r_x, $m+$in_m+1.2*$fontSize+88*$i, true,false,false,true,0,'M');
                    }
                }
            }
            else{
                if (!$measure){
                    $this->pdf->MultiCell( 20, 15, $genTxt, 0, 'C', 1, 2, $w-$m-2*$fontSize+$r_x, $m+$in_m+1.2*$fontSize+88*$i, true);
                }
            }
        }
        $this->pdf->SetTextColor(0,0,0);
        foreach ($indiStack as $gen => $value) {
            $indiStack[$gen]['next']['shift']['left'] =-($gAMaxCount*0.8+0.5)*$fontSize+$r_x;
            $indiStack[$gen]['next']['left']['point'] =$w - $m-46;
            $indiStack[$gen]['next']['top']['x'] =$w - $m;
        }
        $next['shift']['left'] = -($gAMaxCount*0.8+0.5)*$fontSize+$r_x;
        return $next;
    }

    /**
     * @param array  $next  placement directives from previous block
     * @param array $xrefGen individual xref
     * @param int $pos 2~7 ordinary node with circle , 1 top (with uplink triangle) , 8 buttom with downlink triangle
     * @param string $dir 'l' leftwards , 'd' downwards
     * @param bool $measure if true, don't print anything, instead we try to get the space it occupies
     *
     * @return array
     */
    protected function individualBlock($next,$xrefGen,$pos,$dir,$remvChildNum=null,$measure=false):array{
        if ($dir === 'l'){
            $r_x = $next['shift']['left'] ?? $next['prev']['x'] ?? 0;
            $r_y = $next['prev']['y'] ?? 0;
        }
        if ($dir === 'd'){
            $r_x = $next['prev']['x'] ?? 0;
            $r_y = $next['shift']['down'] ?? $next['prev']['y'] ?? 0;
        }
        $pdf = $this->pdf;
        $h = $this->pageHeight;
        $w = $this->pageWidth;
        $fontSize = $this->fontSize;
        $pdf->setCellPaddings(0, 2, 0,2);
        //page margin
        $m = $this->pageMargin;
        //inner frame margin
        $in_m = $this->pageInMargin;
        if (!$measure){
            if($pos === 1 && $this->curOp === self::EXT_BOT_QUE ){
                $this->triangle($w-$m-2*$fontSize+$r_x, $m+$in_m+0.5*$fontSize,60);
            }
            if($pos === 8){
                $this->triangle($w-$m-2*$fontSize+$r_x, $h-$m-$in_m-0.1*$fontSize,0);
            }
            if (2<=$pos && $pos<=8 && ($this->curOp === self::CHILDREN || $this->curOp === self::SIBLINGS)){
                $this->circle($w-$m-2*$fontSize+$r_x, $m+$in_m+0.8*$fontSize+$r_y);
            }
        }
        $indi =new IndividualExt(key($xrefGen), $this->tree);
        $name    =$indi->name();
        $spouses   = $indi->spouDescr();
        $children  =  $indi->chilNumDescr();
        $sex  =  $indi->sex();
        $pdf->SetFont($this->font, 'B', 18);
        $nameArr = mb_str_split($name,3);
        if (!$measure){
            if ($this->colors === 'on' && $sex ==='F'){
                $this->pdf->SetTextColor(196, 30, 58);
            }
            foreach ($nameArr as $i => $iValue) {
                if ($iValue!=null){
                    $pdf->MultiCell(20, 20, $nameArr[$i], 0, 'C', 0, 2, $w - $m - (1.2*$i+2.6)* $fontSize + $r_x, $m + $in_m + $fontSize + $r_y, true);
                }
            }
            if ($this->colors === 'on' && $sex ==='F'){
                $this->pdf->SetTextColor(0,0,0);
            }
//            if($pos !== 8){
//                $this->resumeArr[key($xrefGen)] = current($xrefGen);
//            }
        }
        $pdf->SetFont($this->font, '', 12);
        //six chinese characters will begin a new multicell
        $spouseArr = mb_str_split($spouses,6);
        if($pos!==8){
            if (!$measure){
                foreach ($spouseArr as $i => $iValue) {
                    $spouseArr[$i] = ltrim($spouseArr[$i],'\n');
                    if ($spouseArr[$i]!=null){
                        $pdf->MultiCell( 15, 15, $spouseArr[$i], 0, 'C', 0, 2, $w-$m-($i*0.8+1.2*count($nameArr)+2.4)*$fontSize+$r_x, $m+$in_m+1.1*$fontSize+$r_y, true);
                    }
                }
            }
        }
        else{
            $spouseArr = [];
        }
        $pdf->SetFont($this->font, '', 9);
        if (!$measure && !$remvChildNum){
            if ( $pos!==8){
                $pdf->MultiCell( 15, 15, $children, 0, 'R', 0, 2, $w-$m-2.1*$fontSize+$r_x, $m+$in_m+$fontSize*(1.2*(count($nameArr)>1?3:mb_strlen($name))+1)+$r_y, true);
            }
        }
        $next['prev']['x']=$r_x;
        $next['prev']['y']=$r_y;
        $next['bottom']['x']=$w-$m-2*$fontSize+$r_x;
        $next['bottom']['y']=$m+$in_m+$fontSize*(1+1.25*(count($nameArr)>1?3:mb_strlen($name)))+$r_y;
        $next['top']['x']=$w-$m-2*$fontSize+$r_x-3;
        $next['top']['y']=$m+$in_m+0.8*$fontSize+$r_y;
        $next['shift']['left']= -(0.8*count($spouseArr)+1.2*count($nameArr)+1.1)*$fontSize +$r_x;
        $next['shift']['down']= 88 +$r_y;
        $next['left']['edge']=$w-$m-(0.8*count($spouseArr)+1.2*count($nameArr)+2)*$fontSize+$r_x;
        $next['left']['point']=$w-$m-(0.8*count($spouseArr)+1.2*count($nameArr)+2.9)*$fontSize+$r_x;
        return $next;
    }

    protected function verticalWords($words) :string {
        $wordArray = array_map(function($str) { return  $str. "\n";},mb_str_split($words));
        return implode('',$wordArray);
    }

}
