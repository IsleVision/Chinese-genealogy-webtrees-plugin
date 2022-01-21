<?php


namespace MyCustomNamespace;



use Fisharebest\Webtrees\Exceptions\IndividualNotFoundException;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\GedcomService;
use Illuminate\Support\Collection;

class IndividualExt extends Individual
{



    public function __construct($xref,$tree)
    {
        $indi = Registry::individualFactory()->make($xref, $tree) ;
        if ($indi==null){
            throw new IndividualNotFoundException();
        }
        parent::__construct($indi->xref,$indi->gedcom,$indi->pending,$indi->tree);
    }


    public function siblings(): array
    {
        return $this->childFamilies()->map(static function (Family $family): Collection {
            return $family->children();
        })->flatten()->all();
    }
    public function siblingXrefs(): array
    {
        return $this->children()->map(function (Individual $sibling):string {
            return $sibling->xref();
        })->all();
    }

    //parameter $spouse is used as a filter
    public function children($spouse=null): Collection
    {
        $spouFams = null;
        if ($spouse != null){
            $indiSpouse = Registry::individualFactory()->make($spouse,$this->tree);
            if ($indiSpouse != null){
                $spouFams = $indiSpouse->spouseFamilies();
            }
        }
        return $this->spouseFamilies()->filter(function ($selfFAMS) use($spouFams){
            if ($spouFams==null){
                return true;
            }
            return $spouFams->contains($selfFAMS);
        })->map(static function (Family $family): Collection {
            return $family->children();
        })->flatten();
    }

    public function childrenNames($spouse=null): string
    {
        return $this->children($spouse)->map(function (Individual $child):string {
            return $child->getAllNames()[0]['fullNN'];
        })->filter()->flatten()->join('，');
    }

    public function chilXrefs($spouse=null): array
    {
        return $this->children($spouse)->map(function (Individual $child): string {
            return $child->xref();
        })->all();
    }

    public function chilXrefTypes(): array
    {
        return $this->children()->map(function (Individual $child): array {
            return ['xref'=>$child->xref(),'isGivUp'=>Functions::isGivenUp($this->xref(),$child->xref(),$this->tree)];
        })->all();

    }

    public function spouFamsDescr($spouDate): Collection
    {
        return $this->spouseFamilies()->map(function (Family $family) use ($spouDate): ?string {

            $chilArr =  $family->children()->map(function (Individual $indi): ?string{
                return $indi->getAllNames()[0]['fullNN'];
            })->filter();

            if ($family->husband() === null || $family->wife() === null){
              if ($chilArr->isEmpty()){
                  return null;
              }
              return "子女：".$chilArr->flatten()->join('&nbsp;');
            }
            $husband = new IndividualExt($family->husband()->xref(),$family->tree());
            $wife = new IndividualExt($family->wife()->xref(),$family->tree());
            if ($spouDate){
                $husBirt = $husband->birthDate();
                $husDeat = $husband->deathDate();
                $wifBirt = $wife->birthDate();
                $wifDeat = $wife->deathDate();
                $husbName = $husband->getAllNames()[0]['fullNN'].'('.($husBirt==null?'&nbsp;？&nbsp;':$husBirt).'—'.($husDeat==null?'&nbsp;？&nbsp;':$husDeat).')';
                $wifName = $wife->getAllNames()[0]['fullNN'].'('.($wifBirt==null?'&nbsp;？&nbsp;':$wifBirt).'—'.($wifDeat==null?'&nbsp;？&nbsp;':$wifDeat).')';
            }
            else{
                $husbName = $husband->getAllNames()[0]['fullNN'];
                $wifName = $wife->getAllNames()[0]['fullNN'];
            }
            if ($family->husband()->xref()===$this->xref()){
                if ($chilArr->isEmpty()){
                    return "与".$wifName."无子女";
                }
                return "与".$wifName."的子女：".$chilArr->flatten()->join('&nbsp;');
            }
            if ($chilArr->isEmpty()){
                return "与".$husbName."无子女";
            }
            return "与".$husbName."的子女：".$chilArr->flatten()->join('&nbsp;');
        })->filter()->flatten();
    }

    public function chilDescr(): string
    {
        $chilNamArr = $this->spouseFamilies()->map(function (Family $family): ?string {
            return $family->children()->map(function (Individual $indi): ?string{
                return $indi->getAllNames()[0]['fullNN'];
            })->filter()->join(' ');
        })->filter()->flatten();
        if ($chilNamArr->isEmpty()){
            return '';
        }
        return $chilNamArr->join(' ');
    }

    public function chilNumDescr(): string
    {
        if ($this->children()->isEmpty()){
//            return '不嗣';
            return '';
        }
        return Functions::numToCharacter($this->children()->count()).'子女';
    }

    //$outstand=1 get spouses that need to be displayed in a new line; 0 otherwise; null get all, no filter
    public function spouses($outstand=null): Collection
    {
        return $this->spouseFamilies()->map(function (Family $family) use($outstand): ?IndividualExt {
            if ($family->husband() === null) return null;
            if ($family->husband()->xref()===$this->xref()){
                if ($family->wife() === null) return null;
                $wife = new IndividualExt($family->wife()->xref(),$this->tree);
                if ($outstand === null){
                    return $wife;
                }
                if ($wife->birthDate()===null && $wife->deathDate()===null){
                    if ($outstand){
                        return null;
                    }
                    return $wife;
                }
                if ($outstand){
                    return $wife;
                }
                return null;
            }
            $husband = new IndividualExt($family->husband()->xref(),$this->tree);
            if ($outstand === null){
                return $husband;
            }
            if ($husband->birthDate()===null && $husband->deathDate()===null){
                if ($outstand){
                    return null;
                }
                return $husband;
            }
            if ($outstand){
                return $husband;
            }
            return null;
        })->filter();
    }

    public function spouXrefs($outstand): Collection
    {
        return $this->spouses($outstand)->map(function (IndividualExt $indi):string{
                return  $indi->xref();
            });
    }

    public function spouDescr(): string
    {
        if ($this->spouses()->isEmpty()){
            return '';
        }
        return '配'.$this->spouses()->map(function (IndividualExt $indi):string{
                return  $indi->getAllNames()[0]['fullNN'];
            })->join("配");
    }

    public function name(): string
    {
        return  $this->getAllNames()[0]['fullNN'];
    }

    public function birthDate(): ?string
    {
        $dates = null;
        foreach ($this->facts(['BIRT'], false, null, true) as $event) {
            if ($event->date()->minimumDate()) {
                $rawDate = $event->date()->minimumDate();
                if ($rawDate==null){
                    continue;
                }
                $dates = $rawDate->year().'年'.$rawDate->month().'月'.$rawDate->day().'日';
            }
        }
        return $dates;
    }

    public function birthDateTimePlace(): ?string
    {
        $dateTimePlace = null;
        foreach ($this->facts(['BIRT'], false, null, true) as $event) {
            if ($event->date()->minimumDate()) {
                $rawDate = $event->date()->minimumDate();
                if ($rawDate==null){
                    continue;
                }
                $dateTimePlace = $rawDate->year().'年'.$rawDate->month().'月'.$rawDate->day().'日';
            }
            if (preg_match('/\n3 TIME (.+)/', $event->gedcom(), $match)) {
                $time = explode(':',$match[1]);
                $dateTimePlace .= $time[0].'时'.$time[1].'分';
            }
            $placeFull = $event->place();
            $SHOW_PEDIGREE_PLACES = (int) $this->tree->getPreference('SHOW_PEDIGREE_PLACES');
            $parts = $placeFull->lastParts($SHOW_PEDIGREE_PLACES)->all();
            if ($parts != null){
                $reverParts = array_reverse($parts);
                $dateTimePlace .= '生于'.implode("",$reverParts);
            }
            else if ($dateTimePlace != null){
                $dateTimePlace .= '生';
            }
        }
        return $dateTimePlace;
    }

    public function deathDate(): ?string
    {
        $dates = null;
        foreach ($this->facts(['DEAT'], false, null, true) as $event) {
            if ($event->date()->isOK()) {
                $rawDate = $event->date()->minimumDate();
                if ($rawDate==null){
                    continue;
                }
                $dates = $rawDate->year().'年'.$rawDate->month().'月'.$rawDate->day().'日';
            }
        }
        return $dates;
    }

    public function deathDateTimePlace(): ?string
    {
        $dateTimePlace = null;
        foreach ($this->facts(['DEAT'], false, null, true) as $event) {
            if ($event->date()->isOK()) {
                $rawDate = $event->date()->minimumDate();
                if ($rawDate==null){
                    continue;
                }
                $dateTimePlace = $rawDate->year().'年'.$rawDate->month().'月'.$rawDate->day().'日';
            }
            if (preg_match('/\n3 TIME (.+)/', $event->gedcom(), $match)) {
                $time = explode(':',$match[1]);
                $dateTimePlace .= $time[0].'时'.$time[1].'分';
            }
            $placeFull = $event->place();
            $SHOW_PEDIGREE_PLACES = (int) $this->tree->getPreference('SHOW_PEDIGREE_PLACES');
            $parts = $placeFull->lastParts($SHOW_PEDIGREE_PLACES)->all();
            if ($parts != null){
                $reverParts = array_reverse($parts);
                $dateTimePlace .= '卒于'.implode("",$reverParts);
            }
            else if ($dateTimePlace != null){
                $dateTimePlace .= '卒';
            }
        }
        return $dateTimePlace;
    }

    public function residency() :string{
        $residency = '';
        $resi = $this->facts(['RESI'], false, null, true);
        foreach ($resi as $event) {
            $placeFull = $event->place();
            $SHOW_PEDIGREE_PLACES = (int) $this->tree->getPreference('SHOW_PEDIGREE_PLACES');
            $parts = $placeFull->lastParts($SHOW_PEDIGREE_PLACES)->all();
            if ($parts != null){
                $reverParts = array_reverse($parts);
                $residency .= implode("",$reverParts);
            }
        }
        return $residency;
    }

    public function education() :string{
        $education = '';
        foreach ($this->facts(['GRAD'], false, null, true) as $event) {
            $education .= $event->attribute('TYPE') ;
            if ($event->attribute('AGNC')!=null){
                $education .= '('.$event->attribute('AGNC').')';
            }
        }
        return $education;
    }

    public function note() :string{
        $note = '';
        foreach ($this->facts(['NOTE'], false, null, true) as $event) {
            $note .= $event->value();
        }
        return $note;
    }

    public function occupation() :string{
        $occupation = '';
        foreach ($this->facts(['OCCU'], false, null, true) as $event) {
            $occupation .= $event->value();
            if ($event->attribute('AGNC')!=null){
                $occupation .= '('.$event->attribute('AGNC').')';
            }
        }
        return $occupation;
    }

    public function caste() :string{
        $caste = '';
        foreach ($this->facts(['CAST'], false, null, true) as $event) {
            $caste .= $event->value();
        }
        return $caste;
    }

    public function imageUrl() : ?string{
        $media_file = $this->findHighlightedMediaFile();
        if ($media_file==null){
            return null;
        }
        $path = 'data/media/'.$media_file->filename();
        if (file_exists($path)){
            return $path;
        }
        return null;
    }

        /**

        if ($media_file !== null) {
            $tmp = $media_file->filename();
            //file name is combined with individual xref and media xref to ensure its name is unique
            $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.$this->xref().'_'.$media_file->media()->xref().'.tmp';
            $path =  __DIR__.'/../media/'.$this->xref().'_'.$media_file->media()->xref().'.tmp';
            if (file_exists($path)){
                return 'modules_v4/chinese-hanging-report/media/'.$this->xref().'_'.$media_file->media()->xref().'.tmp';
            }
//             The "mark" parameter is ignored, but needed for cache-busting.
            $url =  route(MediaFileDownload::class, [
                'xref'        => $media_file->media()->xref(),
                'tree'        => $media_file->media()->tree()->name(),
                'fact_id'     => $media_file->factId(),
                'disposition' => 'inline',
                'mark'        => Registry::imageFactory()->fileNeedsWatermark($media_file, Auth::user())
            ]);
            $opts = array('http' => array('header'=> 'Cookie: ' . $_SERVER['HTTP_COOKIE']."\r\n"));
            $context = stream_context_create($opts);
            $headers = substr(get_headers($url)[0], 9, 3);
            if($headers != "200"){
                return '';
            }
            $imageContent = file_get_contents($url, false, $context);
            //this is webtrees default 404 svg image starting words
            $page404 = '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100">';
            if (str_starts_with($imageContent,$page404)){
                return '';
            }
            file_put_contents ($path, $imageContent);
            return 'modules_v4/chinese-hanging-report/media/'.$this->xref().'_'.$media_file->media()->xref().'.tmp';
        }
        return '';

    }
*/

}