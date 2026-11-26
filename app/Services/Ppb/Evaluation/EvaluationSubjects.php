<?php
declare(strict_types=1);
namespace App\Services\Ppb\Evaluation;

use App\Models\PpbAnalyst;
use App\Services\Ppb\LegacyDisplayLabels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Legacy IDs, V2 analyst IDs and login-account IDs are never interchangeable. */
class EvaluationSubjects
{
    public function __construct(private LegacyReader $reader) {}

    public function mappings(): array
    {
        $pairs=DB::table('ppb_submissions as s')->join('ppb_legacy_pick_contents as p','p.source_pick_id','=','s.legacy_source_pick_id')
            ->where('s.status','legacy_imported')->whereNotNull('s.submitted_analyst_id')->whereColumn('s.game_id','p.game_id')->whereColumn('s.kind','p.kind')
            ->select('p.legacy_analyst_id','s.submitted_analyst_id')->distinct()->get();
        $forward=[];$reverse=[];
        foreach($pairs as $pair){$a=(int)$pair->legacy_analyst_id;$b=(int)$pair->submitted_analyst_id;if($a<=0||$b<=0)continue;$forward[$a][$b]=true;$reverse[$b][$a]=true;}
        $verified=[];
        foreach($forward as $legacy=>$candidates){
            if(count($candidates)!==1)continue;
            $native=(int)array_key_first($candidates);
            if(count($reverse[$native])===1)$verified[(int)$legacy]=$native;
        }
        // Explicit links (migration 000018) win over derived evidence; keep the mapping one-to-one.
        $links=$this->explicitLinks();
        foreach($verified as $legacy=>$native)if(isset($links[$legacy])||in_array($native,$links,true))unset($verified[$legacy]);
        return $links+$verified;
    }

    /** @return array<int,int> legacy analyst id => V2 analyst id */
    private function explicitLinks(): array
    {
        if(!Schema::hasTable('ppb_legacy_analyst_links'))return [];
        $links=[];
        foreach(DB::table('ppb_legacy_analyst_links')->get(['legacy_analyst_id','analyst_id']) as $row)$links[(int)$row->legacy_analyst_id]=(int)$row->analyst_id;
        return $links;
    }

    public function all(): array
    {
        $source=$this->reader->profiles();
        $maps=$this->mappings();
        $optional=array_intersect(['rating_level','analyst_level'],Schema::getColumnListing('ppb_analysts'));
        $native=PpbAnalyst::query()->orderBy('id')
            ->get(['id','name','display_name','status','user_type',...$optional])->keyBy('id');
        $out=[];
        foreach($source as $profile){
            if(!in_array((int)$profile['user_type'],[0,1],true))continue;
            $id=(int)$profile['id'];$local=isset($maps[$id])?$native->get($maps[$id]):null;
            $out[]=['type'=>'legacy','id'=>$id,'label'=>$local?($local->display_name?:$local->name):LegacyDisplayLabels::unmappedAnalyst($id),
                'native_id'=>$local?(int)$local->id:null,'mapped'=>(bool)$local,'sort'=>(int)$profile['sort_id'],
                'rating_level'=>isset($profile['rating_level'])?(int)$profile['rating_level']:null,
                'analyst_level'=>isset($profile['analyst_level'])?(int)$profile['analyst_level']:null,'user_type'=>(int)$profile['user_type'],'status'=>$local?->status];
        }
        foreach($native as $analyst){
            if(!in_array((int)$analyst->user_type,[0,1],true)||in_array((int)$analyst->id,$maps,true))continue;
            $out[]=['type'=>'v2','id'=>(int)$analyst->id,'label'=>$analyst->display_name?:$analyst->name,
                'analyst_level'=>$analyst->analyst_level, 'native_id'=>(int)$analyst->id,'mapped'=>true,'sort'=>100000+(int)$analyst->id,'rating_level'=>$analyst->getRawOriginal('rating_level')===null?null:(int)$analyst->rating_level,'user_type'=>(int)$analyst->user_type,'status'=>$analyst->status];
        }
        usort($out,fn($a,$b)=>[$a['sort'],$a['id']]<=>[$b['sort'],$b['id']]);return $out;
    }

    public function get(string $type,int $id): array
    {
        foreach($this->all() as $subject)if($subject['type']===$type&&$subject['id']===$id)return $subject;
        abort(404);
    }

    public function forNative(int $id): array
    {
        foreach($this->mappings() as $legacy=>$native)if($native===$id)return ['type'=>'legacy','id'=>$legacy];
        return ['type'=>'v2','id'=>$id];
    }
}
