"""The two layers of Auto Scout, and what each one is made of.

Perception (reused every season): finding robots, tracking them, reading bumper numbers, knowing who is
who, and understanding the camera/field layout. It does not depend on the game.

Game layer (retrained each season): the Game Builder definition, the action heads trained on top of the
shared clip features, the season's field elements (e.g. 2026 hubs), and the owner's scoring rules.

Each trained model records both identities, so a model's provenance shows which perception it was trained
against and a perception upgrade is visible next to every game model built on it.
"""
import hashlib
import json
from pathlib import Path

VERSION = 'perception-layer-v1'

# Field-detector classes by layer: corners describe any FRC field; scoring elements change each game.
FIELD_CLASSES = {'perception': ['field_corner'], 'game': ['scoring_target']}


def _sha(path):
    path = Path(path)
    return hashlib.sha256(path.read_bytes()).hexdigest() if path.is_file() else None


def perception_identity():
    """Versions and fingerprints of every game-independent component."""
    import neptune_identity
    import neptune_yolo
    root = Path(__file__).parent
    parts = {
        'robot_detector': neptune_yolo.fingerprint(),
        'tracking': neptune_yolo.VERSION+'/'+neptune_yolo.CONTINUITY_REVISION,
        'identity_solver': neptune_identity.VERSION,
        'field_layout_detector': _sha(root/'field-detector'/'model.pt'),
    }
    return {'version': VERSION, 'components': parts,
            'sha256': hashlib.sha256(json.dumps(parts, sort_keys=True).encode()).hexdigest()}


def game_identity(game, feature_version=None):
    """The season-specific layer a model was trained for."""
    root = Path(__file__).parent
    parts = {'game_key': game.get('game_key'), 'game_fingerprint': game.get('fingerprint'),
             'action_features': feature_version, 'field_elements_reference': _sha(root/'field-detector'/'reference.json')}
    return {'components': parts, 'sha256': hashlib.sha256(json.dumps(parts, sort_keys=True).encode()).hexdigest()}
