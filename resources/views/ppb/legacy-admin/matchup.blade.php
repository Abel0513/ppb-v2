{{ $game->matchup_text ?: trim(($game->league ?: '').' '.($game->away_team ?: '').' @ '.($game->home_team ?: '')) }}
