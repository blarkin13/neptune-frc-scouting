"""Fetch the centrally released game model. Helpers never train or publish models."""
import json
from pathlib import Path
import neptune_games as games
from neptune_helper import request, public_https


def refresh(site, season, home=games.HOME):
    data=request(public_https(site.rstrip('/')+'/community/models.php?season='+str(int(season))))
    if data.get('schema_version')!=1 or data.get('season')!=int(season):
        raise ValueError('Neptune returned an unsupported game model response.')
    pack=data.get('game_pack')
    if pack:
        pack=dict(pack,season=int(season));games.validate(pack,str(season))
        folder=Path(home)/'games';folder.mkdir(parents=True,exist_ok=True)
        target=folder/(str(season)+'.json');temp=target.with_suffix('.tmp')
        temp.write_text(json.dumps(pack));temp.replace(target)
    game=games.select(str(season)+'shared',home)
    model=data.get('model')
    if model:
        from neptune_training import model_valid
        model_valid(model,game)
        bench=data.get('benchmark') or {}
        if not model.get('release_ready') or not (model.get('heads') or model.get('scoring_head')) or not bench.get('release_ready') or bench.get('model_id')!=model['model_id']:
            raise ValueError('The shared model has not passed its release checks.')
    # Cached metadata is only for display. Every analysis checks the server again,
    # so a retired model or a withdrawn training label cannot silently stay active.
    dest=Path(home)/'shared-models';dest.mkdir(parents=True,exist_ok=True)
    target=dest/(str(season)+'.json');tmp=target.with_suffix('.tmp');tmp.write_text(json.dumps(data));tmp.replace(target)
    return data,game,model
