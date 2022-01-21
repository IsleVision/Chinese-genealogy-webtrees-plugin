<?php

namespace MyCustomNamespace;

use Fisharebest\Algorithm\ConnectedComponent;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Capsule\Manager as DB;

class Functions
{
    /**
     * resolve individual's generation in his genealogy tree
     *
     * @return string
     */
    public static function searchGeneration(string $indiXref, Tree $tree): string
    {
        $cachedGen =  Registry::cache()->file()->remember("generation-".$indiXref,function () :string{return '';});
        $individual = Registry::individualFactory()->make( $indiXref, $tree);
        if($individual === null)return '';
        $indiName =  $individual->getAllNames()[0]['fullNN'];
        //read generation match table from external ini file
        $generations = Registry::cache()->array()->remember('generation-table',function (): array{
            return parse_ini_file(ChineseHangingModule::GENERATIONS);
        }
        );
        $matchedGen = -1;
        //only individual's name with 3 characters can determine generation
        if (mb_strlen($indiName)==3){
            $matchedGen = $generations[mb_substr($indiName,1,1)]?? -1;
        }
        //if the generation is not resolved, then try to match his children's generation
        if ($matchedGen==-1){
            foreach ($individual->spouseFamilies() as $spouseFamily){
                foreach ($spouseFamily ->facts(['CHIL'], false, Auth::accessLevel($tree)) as $fact){
                    $child = $fact->target();
                    $childName =  $child->getAllNames()[0]['fullNN'];
                    $chidGen = -1;
                    if (mb_strlen($childName)==3){
                        $chidGen = $generations[mb_substr($childName,1,1)]?? -1;
                    }
                    //childGen can't be 1 as this will cause his parent's generation to be 0
                    if ($chidGen<>-1&&$chidGen<>1){
                        return (string)$chidGen-1;
                    }
                }
            }
        }
        //only when gen is not matched, we use cachedGen
        if ($matchedGen === -1){
            return $cachedGen;
        }
        return (string)$matchedGen;
    }


    /**
     * get all the individuals who are adopted
     *
     * @return array
     */
    public static function getAllAdoptedIndis( Tree $tree): array{
       return Registry::cache()->array()->remember('all-adopted-indis',function () use ($tree): array{
            return DB::table('individuals')
                ->where('i_file', '=', $tree->id())
                ->pluck('i_gedcom','i_id')
                ->filter(static function (string $gedcom) {
                    return preg_match('/\n1 FAMC @.+@\n2 PEDI (?:adopted|foster)/i', $gedcom);
                })
                ->keys()->all();
        });

    }

    public static function isAdopFost($parXref, $childXref,$tree): bool{
        //use this cached data to expedite process
        if (!in_array($childXref, Functions::getAllAdoptedIndis($tree), true)){
            return false;
        }
        return !self::isGivenUp($parXref, $childXref,$tree);
    }

    /**check whether the father/mother gives his/her child to others(give up family relationship)
     * @param $parXref
     * @param $childXref
     * @param $tree
     * @return bool
     */
    public static function isGivenUp($parXref, $childXref,$tree): bool{
        //use this cached data to expedite process
        if (!in_array($childXref, Functions::getAllAdoptedIndis($tree), true)){
            return false;
        }
        $access_level =  Auth::accessLevel($tree);
        if ($tree->getPreference('SHOW_PRIVATE_RELATIONSHIPS') === '1') {
            $access_level = Auth::PRIV_HIDE;
        }
        $par = Registry::individualFactory()->make($parXref, $tree);
        $chil = Registry::individualFactory()->make($childXref, $tree);
        if (!$par|| !$chil) return false;
        foreach ($chil->facts(['FAMC'], false, $access_level) as $fact) {
            $family = $fact->target();
            if ($family instanceof Family && $family->canShow($access_level)) {
                preg_match('/\n1 FAMC @' . $family->xref() . '@(?:\n[2-9].*)*\n2 PEDI (.+)/', $chil->gedcom(), $match);
                $type = $match[1] ?? 'birth';
                $familiesXT[$family->xref()] = $type;
            }
        }
        $parFams = $par->spouseFamilies()->map(static function (Family $family): string {
            return $family->xref();
        })->all();

        $filFamsXT = array_filter($familiesXT, function($k) use ($parFams) {
            return in_array($k, $parFams);
        },ARRAY_FILTER_USE_KEY);

        foreach ($filFamsXT as $type){
            if (strtolower($type)==='birth'){
                return true;
            }
        }
        return false ;
    }


    /**
     * get all the family branches which are mutually apart
     *
     * @return array
     */
    public static function getAllBranches(Tree $tree): array{
        $links = ['FAMS', 'FAMC'];
        $rows = DB::table('link')
            ->where('l_file', '=', $tree->id())
            ->whereIn('l_type', $links)
            ->select(['l_from', 'l_to'])
            ->get();
        $graph = DB::table('individuals')
            ->where('i_file', '=', $tree->id())
            ->pluck('i_id')
            ->mapWithKeys(static function (string $xref): array {
                return [$xref => []];
            })
            ->all();
        foreach ($rows as $row) {
            $graph[$row->l_from][$row->l_to] = 1;
            $graph[$row->l_to][$row->l_from] = 1;
        }
        $algorithm  = new ConnectedComponent($graph);
        $components = $algorithm->findConnectedComponents();
        $individual_groups = [];
        foreach ($components as $component) {
            $individual_groups[] = DB::table('individuals')
                ->where('i_file', '=', $tree->id())
                ->whereIn('i_id', $component)
                ->get()
                ->map(Registry::individualFactory()->mapper($tree))
                ->filter();
        }
        return $individual_groups;
    }


    public static function getIndiAnces ($indiXref , Tree $tree) : array{
        if ($tree->getPreference('SHOW_PRIVATE_RELATIONSHIPS') === '1') {
            $access_level = Auth::PRIV_HIDE;
        }
        else{
            $access_level = Auth::accessLevel($tree);
        }
        $indi = Registry::individualFactory()->make($indiXref,$tree);
        $ancesType = [];
        if ($indi instanceof Individual){

            foreach ($indi->facts(['FAMC'], false, $access_level) as  $fact) {
                $family = $fact->target();
                if ($family instanceof Family && $family->canShow($access_level)) {
                    //both are null, very odd, might be a fault situation
                    if ($family->wife()===null && $family->husband() === null){
                        continue;
                    }
                    //one of the parents is null
                    if ($family->wife() === null) {
                        $husbandXref = $family->husband()->xref();
                        $ancesType[$husbandXref]= self::isAdopFost($husbandXref,$indiXref,$tree);
                        continue;
                    }

                    if ($family->husband() === null){
                        $wifeXref = $family->wife()->xref();
                        $ancesType[$wifeXref]= self::isAdopFost($wifeXref,$indiXref,$tree);
                        continue;
                    }
                    //both exist
//both have no parents, might be the top ancestor
                    if ($family->husband()->childFamilies()->isEmpty() && $family->wife()->childFamilies()->isEmpty()){
                        //male is first considered as a genealogy member
                        $husbandXref = $family->husband()->xref();
                        $ancesType[$husbandXref]= self::isAdopFost($husbandXref,$indiXref,$tree);
                        continue;
                    }
                    //either has parents
                    if ($family->wife()->childFamilies()->isEmpty()) {
                        $husbandXref = $family->husband()->xref();
                        $ancesType[$husbandXref]= self::isAdopFost($husbandXref,$indiXref,$tree);
                        continue;
                    }

                    if ($family->husband()->childFamilies()->isEmpty()){
                        $wifeXref = $family->wife()->xref();
                        $ancesType[$wifeXref]= self::isAdopFost($wifeXref,$indiXref,$tree);
                    }
                    //both have parents
//                                else{
//                                    $branchStackTmp =[$family->husband()->xref()=>$gen-$depth,$family->xref()=>$family->wife()->xref()]   ;
//                                    $branchStacks []= [$indiXrefGen,[$family->wife()->xref() => $gen-$depth,$family->xref()=>$family->husband()->xref()]]  ;
//                                }
                }
            }
        }
        return $ancesType;
    }


    /**
     * Get an array of this individual’s ancestor backbones
     *
     * @param string $indiXref
     * @param int $maxGen the depth num of gens to look up
     * @param Tree $tree
     * @return array
     */
    public static function getIndiBranches($indiXrefGen, $maxGen , Tree $tree): array
    {
        if ($tree->getPreference('SHOW_PRIVATE_RELATIONSHIPS') === '1') {
            $access_level = Auth::PRIV_HIDE;
        }
        else{
            $access_level = Auth::accessLevel($tree);
        }
        $gen = end($indiXrefGen);
        $xref = key($indiXrefGen);
        $branchStacks = [[$indiXrefGen]] ;
        $branchStackTmp = null;
        for ($depth =1 ; $depth<$maxGen && $depth<$gen ; $depth++){
            foreach ($branchStacks as $i => $branchStack){
                $xrefFam = end($branchStack);
                reset($xrefFam);
                $indiXref = key($xrefFam);
                $indi = Registry::individualFactory()->make($indiXref,$tree);
                if ($indi instanceof Individual){
                    $j = 1;
                    foreach ($indi->facts(['FAMC'], false, $access_level) as  $fact) {
                        $family = $fact->target();
                        if ($family instanceof Family && $family->canShow($access_level)) {
                            //both are null, very odd, might be a fault situation
                            if ($family->wife()===null && $family->husband() === null){
                                continue;
                            }
                            //one of the parents is null
                            if ($family->wife() === null) {
                                $husbandXref = $family->husband()->xref();
                                if (self::isGivenUp($husbandXref,$indiXref,$tree)){
                                    continue;
                                }
                                $branchStackTmp =[$family->husband()->xref()=>$gen-$depth,$family->xref()=>null];
                            }
                            else if ($family->husband() === null){
                                $wifeXref = $family->wife()->xref();
                                if (self::isGivenUp($wifeXref,$indiXref,$tree)){
                                    continue;
                                }
                                $branchStackTmp = [$family->wife()->xref()=>$gen-$depth,$family->xref()=>null];
                            }
                            //both exist
                            else{
                                //both have no parents, might be the top ancestor
                                if ($family->husband()->childFamilies()->isEmpty() && $family->wife()->childFamilies()->isEmpty()){
                                    //male is first considered as a genealogy member
                                    if (self::isGivenUp($husbandXref,$indiXref,$tree)){
                                        continue;
                                    }
                                    $branchStackTmp =[$family->husband()->xref()=>$gen-$depth,$family->xref()=>$family->wife()->xref()];
                                }
                                //either has parents
                                if ($family->wife()->childFamilies()->isEmpty()) {
                                    $husbandXref = $family->husband()->xref();
                                    if (self::isGivenUp($husbandXref,$indiXref,$tree)){
                                        continue;
                                    }
                                    $branchStackTmp =[$family->husband()->xref()=>$gen-$depth,$family->xref()=>$family->wife()->xref()];
                                }
                                else if ($family->husband()->childFamilies()->isEmpty()){
                                    $wifeXref = $family->wife()->xref();
                                    if (self::isGivenUp($wifeXref,$indiXref,$tree)){
                                        continue;
                                    }
                                    $branchStackTmp =   [$family->wife()->xref()=>$gen-$depth,$family->xref()=>$family->husband()->xref()];
                                }

                                //both have parents
                                else{
                                    $husbandXref = $family->husband()->xref();
                                    if (self::isGivenUp($husbandXref,$indiXref,$tree)){
                                        continue;
                                    }
                                    $branchStackTmp =[$family->husband()->xref()=>$gen-$depth,$family->xref()=>$family->wife()->xref()];
                                }
//                                else{
//                                    $branchStackTmp =[$family->husband()->xref()=>$gen-$depth,$family->xref()=>$family->wife()->xref()]   ;
//                                    $branchStacks []= [$indiXrefGen,[$family->wife()->xref() => $gen-$depth,$family->xref()=>$family->husband()->xref()]]  ;
//                                }
                            }

                            if ($branchStackTmp!=null){
                                if ($j>1){
                                    $branchStacks []= [$indiXrefGen,$branchStackTmp];
                                }
                                else{
                                    $branchStacks[$i] []= $branchStackTmp;
                                }
                            }

                            $j++;
                        }
                    }
                }
            }
        }

        return $branchStacks;
    }


    public static function chilXrefTypes($parXref, $children,$tree): array
    {
        return $children->map(function (Individual $child) use ($parXref,$tree): array {
            return ['xref'=>$child->xref(),'isGivUp'=>Functions::isGivenUp($parXref,$child->xref(),$tree)];
        })->all();

    }


    /**
     * get the top ancestor of a branch
     *
     * @return string
     */
    public static function searchTopAncestor(array $branch): string{
        $indis = $branch;
        foreach ($indis as $indi){
            if ($indi===null) continue;
            //the person, whose parents and spouses' parents are null, is positioned at the top of the branch
            if ($indi->childFamilies()->isNotEmpty()) continue;
            if ($indi->sex() === 'F') continue;//a female is not considered as a top ancestor
            if ($indi->spouseFamilies()
                ->map(static function (Family $family) use ($indi): ?Individual {
                    return $family->spouse($indi);
                })->filter()
                ->filter(static function (Individual $indi) : bool {
                    return $indi->childFamilies()->isNotEmpty();
                })->isNotEmpty()) continue;
            return $indi->xref();
        }
        return '';
    }

    /**
     *converts number to Chinese character
     *@return string
     **/
    public static function numToCharacter($int){
        $unitArr = [1 => '',2 => '十',3 => '百',4 => '千'];
        $multiUnitArr = [0 => '',1 => '万', 2 => '亿'];
        $digitArr = ['零','一','二','三','四','五','六','七','八','九'];
        $combine = '';
        $residue = floor((strlen($int) / 4));
        $mol = strlen($int) % 4;
        for($b =  $residue + 1; $b >= 1; ){
            $length = $b == ($residue + 1) ? $mol : 4;
            $b--;
            $st = substr($int,($b * (-4)) - 4, $length);
            if($st !== ''){
                for ($a = 0, $aMax = strlen($st); $a < $aMax; $a++) {
                    if ((int)$st[$a] === 0) {
                        $combine .=  '零';
                    }
                    else{
                        $combine .= $digitArr[(int)$st[$a]].$unitArr[strlen($st)-$a];
                    }
                }
                $combine .= $multiUnitArr[$b];
            }
        }
    $j = 0;
    $slen = strlen($combine);
    while ($j < $slen) {
        $m = substr($combine, $j, 6);
        if ( $m === '零'|| $m === '零万' || $m === '零亿' || $m === '零零') {
            $left = substr($combine, 0, $j);
            $right = substr($combine, $j + 3);
            $combine = $left . $right;
            $j = $j-3;
            $slen = $slen-3;
        }
        $j = $j + 3;
    }
    if(strpos($combine, '一十') === 0){
        return ltrim($combine,'一');
    }
    return $combine;
}

}