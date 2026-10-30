"""Shared engine identity. Server and desktop ship these exact source files."""
import hashlib
from pathlib import Path

VERSION = '2.7.65-polygon-boundaries'
FILES = ('neptune_hard_frames.py', 'neptune_field_training.py', 'neptune_image_folder.py', 'image-folder-labeler.html', 'neptune_external_bumpers.py', 'neptune_trainer.py', 'neptune_ui.py', 'neptune_cache.py', 'neptune_page_worker.py', 'neptune_action_labels.py', 'neptune_training.py', 'neptune_scoring_model.py', 'neptune_clock.py',
         'neptune_events.py', 'neptune_tracking.py', 'neptune_yolo.py', 'neptune_identity.py', 'neptune_persistent_identity.py', 'neptune_constant_identity.py', 'neptune_identity_review.py', 'neptune_review_learning.py', 'neptune_review_cloud.py', 'neptune_box_follow.py', 'neptune_temporal.py', 'neptune_detector_training.py', 'requirements-yolo.txt', 'neptune_facts.py',
         'neptune_ocr.py', 'neptune_video_labels.py', 'neptune_games.py', 'season_rules.json',
         'neptune_engine.py','neptune_videomae.py','requirements-videomae.txt','neptune_quality.py','neptune_scouting_rules.py','neptune_field_regions.py','neptune_homography.py','neptune_reacquisition.py','neptune_housekeeping.py','neptune_field_detector.py','neptune_perception.py','neptune_robot_labels.py','neptune_robot_jobs.py', 'neptune_steps.py', 'neptune_review_video.py', 'neptune_score_moments.py', 'neptune_selfcheck.py', 'neptune_awake.py', 'neptune_telemetry.py', 'neptune-logo-square.png', 'neptune-logo-wide.png', 'neptune_layouts.py', 'neptune_helper.py', 'neptune_learning.py', 'neptune_setup.py', 'neptune_shared_models.py', 'hippocampus-logo.png', 'neptune-icons.png', 'neptune-icons-LICENSE.txt', 'neptune-art.jpg')


def identity():
    root = Path(__file__).parent
    hashes = {name: hashlib.sha256((root/name).read_bytes()).hexdigest() for name in FILES}
    return {'version': VERSION, 'files': hashes,
            'sha256': hashlib.sha256(''.join(k+v for k,v in sorted(hashes.items())).encode()).hexdigest()}


# Reviewed processing-equivalent predecessor; invalidate reuse on any processing source change.
_PREPARATION_COMPATIBILITY = {'neptune_cache.py': '6c8d577198b20c9d4ab5c538d9e3010caf74bc9ea5cd57d596ccd419b88ff563', 'neptune_page_worker.py': '32ebda56170ea0a9556c1a5093b7582dcbae0d8eb8a11d0cde486725f6060f7b', 'neptune_action_labels.py': 'a2e4bf0527fa975ba6bec3d4152713391ba4426210faf85dd9d8fbcb66e06c20', 'neptune_training.py': '3553a5c0d4903a65db390d96d6790dd426a0d84c00c694cf9ba1d95e2a8e53ae', 'neptune_scoring_model.py': '6acbb5d405e5b1b9814882faf9b5232f120280779884d119290e0c5e41618f4b', 'neptune_clock.py': '030f4f222e49b2ddd7942e4a514d97c365e4b0803300a92c27f88cf038145927', 'neptune_events.py': '56d512c0d1f7190f0828ddebb99dec5f4c7bba4454608547ab888ccfdfe2f7e8', 'neptune_tracking.py': '8725a5c74c8814202ca8fe23e6e04ab963665ccf01675a1890b501a0d6dbba29', 'neptune_yolo.py': '0b5725ca2e248ce2bd2b42d01ddeab5ff541ba1f0a682dde9c566b3c78779e47', 'neptune_temporal.py': 'ea1778ecc654166f25ca728869185bec61245a6e034fe340769263e8e1070034', 'neptune_detector_training.py': '5273263a5953dde15cfa01734f0774b2f2bbbebb218ce763e5c7aaf59ed03dba', 'requirements-yolo.txt': '5e2d9868023b353f4b908c515f625a3ef260ef3d75217c68c8ac9d343a16da53', 'neptune_facts.py': 'a99716e396c67bd06fcd85f524ae00f251977252949610e7261fd464809a7108', 'neptune_ocr.py': 'fe118142974fd64565cbbcde695e372379011210c0ae0813590ffeeb1430326b', 'neptune_video_labels.py': '4f8a54c13b99babcc867b259adf136968fc01eca8e3e9042f0fb42ce72331ae2', 'neptune_games.py': 'd8bbfb5a86225b26a58768a832c73089c5643b943ead81717abaacf2ea146bc6', 'season_rules.json': '85456d5db090e453a5a24b293d63457ab6d6effcef78eb33f94368f231577670'}

def compatible_preparation_engines():
    root=Path(__file__).parent
    if all(hashlib.sha256((root/name).read_bytes()).hexdigest()==sha for name,sha in _PREPARATION_COMPATIBILITY.items()):
        return ['2876b4bf7917301c661c545ecaab725220e55db322240ad9743e349168abb7b8']
    return []
