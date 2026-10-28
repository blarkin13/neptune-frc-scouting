"""Opt-in VideoMAE evaluation backend; fixed public weights, no remote code.

The original Kinetics classifier is removed. Neptune learns separate game-action
heads; Kinetics labels are never interpreted as FRC observations.
"""
import functools
import hashlib
import math
import os
from pathlib import Path

VERSION='robot-videomae-base-clip-v1'
DIMENSION=768
REPOSITORY='MCG-NJU/videomae-base-finetuned-kinetics'
REVISION='488eb9a0565f257b32866000305c8178965eb9f6'
FILES={'model.safetensors':'065cfde56f97671da4d196e1a3a6d3cf9304dcad92eb671e3632a6c6cdd04f73',
       'config.json':'6d3c7afb3d1430fa478757425cc9be2fcc50a17bfbf58921e8a9e65bb3b5c568',
       'preprocessor_config.json':'c3aa722f22a7ff0d234d407862025fe47672f74f46f92c91f757f7a7354107c2'}


def encoder_document():
    return {'name':'huggingface-videomae-base-kinetics400-v1','feature_version':VERSION,
            'dimension':DIMENSION,'repository':REPOSITORY,'revision':REVISION,
            'weights_sha256':FILES['model.safetensors'],'frames':16,'fps':4,'seconds':4,
            'backbone_frozen':True,'head_training':'per-game-Neptune-action-labels',
            'license':'cc-by-nc-4.0'}


def weights_path():
    return Path(os.environ.get('NEPTUNE_VIDEOMAE_MODEL_DIR',Path.home()/'.neptune-trainer/action-encoder/videomae-base'))


def verify_weights():
    root=weights_path()
    for name,sha in FILES.items():
        p=root/name
        if not p.is_file() or p.is_symlink() or hashlib.sha256(p.read_bytes()).hexdigest()!=sha:
            raise ValueError('VideoMAE evaluation weights/configuration are missing or changed: '+name)
    return root


@functools.lru_cache(maxsize=2)
def encoder(device):
    import torch
    from transformers import VideoMAEForVideoClassification,VideoMAEImageProcessor
    root=verify_weights()
    processor=VideoMAEImageProcessor.from_pretrained(str(root),local_files_only=True)
    model=VideoMAEForVideoClassification.from_pretrained(str(root),local_files_only=True,use_safetensors=True,attn_implementation='sdpa')
    model.classifier=torch.nn.Identity()
    model.requires_grad_(False);model.eval();model.to(device)
    if device=='cpu':
        import os
        torch.set_num_threads(max(1,(os.cpu_count() or 2)-1))   # all but one core
    return model,processor


def features(video,sight,duration_ms,facts):
    import torch
    from neptune_temporal import plan,decoded_crops
    clip=plan(facts,sight)
    clip.update(version=VERSION,weights_sha256=FILES['model.safetensors'])
    device=os.environ.get('NEPTUNE_TRAIN_DEVICE','cpu')
    if device=='cuda':device='cuda:0'
    if device not in ('cpu','mps','cuda:0'):raise ValueError('Unsupported VideoMAE device.')
    model,processor=encoder(device)
    frames=decoded_crops(video,clip)
    inputs=processor(list(frames),return_tensors='pt')
    with torch.inference_mode():
        vector=model(**{k:v.to(device) for k,v in inputs.items()}).logits.squeeze(0).float().cpu().tolist()
    if len(vector)!=DIMENSION or not all(math.isfinite(v) for v in vector):raise ValueError('Invalid VideoMAE action embedding.')
    sight['temporal_clip']=clip
    return vector
