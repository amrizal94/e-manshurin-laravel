"""
E-Manshurin Face Service — InsightFace ArcFace (512-dim)
Pola sama dengan FaceHRM face-service (proven in production).

Setup:
  python -m venv venv
  venv/Scripts/pip install fastapi "uvicorn[standard]" python-multipart numpy pillow opencv-python-headless onnxruntime insightface

Run:
  venv/Scripts/python server.py

Endpoints:
  GET  /health   -> { ok, model, uptime }
  POST /extract  -> { descriptor[512], confidence, pose, box }
"""

import io
import time

import cv2
import numpy as np
from PIL import Image

from fastapi import FastAPI, File, UploadFile, HTTPException
from fastapi.responses import JSONResponse

from insightface.app import FaceAnalysis

START_TIME = time.time()
app = FastAPI()
face_app: FaceAnalysis | None = None
MODEL_NAME = "buffalo_sc"  # kecil+cepat; ganti "buffalo_l" untuk akurasi lebih tinggi


def load_model():
    global face_app
    fa = FaceAnalysis(name=MODEL_NAME, providers=["CPUExecutionProvider"])
    fa.prepare(ctx_id=-1, det_size=(320, 320))
    face_app = fa
    print(f"[face-service] InsightFace {MODEL_NAME} loaded OK")


@app.on_event("startup")
def _startup():
    # Wajib lewat startup event, bukan hanya __main__ guard di bawah —
    # deploy production jalanin `uvicorn server:app` (import sebagai modul,
    # __name__ != "__main__"), jadi load_model() harus nempel ke lifecycle FastAPI.
    load_model()


def pil_to_bgr(pil_img: Image.Image) -> np.ndarray:
    rgb = pil_img.convert("RGB")
    return cv2.cvtColor(np.array(rgb), cv2.COLOR_RGB2BGR)


def estimate_pose(kps: np.ndarray | None, bbox) -> dict:
    """Estimasi pose kepala dari 5-point landmarks. pose: front|left|right|up|down."""
    if kps is None or len(kps) < 3:
        return {"yaw": 0.0, "pitch": 0.0, "pose": "front"}

    left_eye, right_eye, nose = kps[0], kps[1], kps[2]
    eye_mid = (left_eye + right_eye) / 2
    eye_dist = abs(right_eye[0] - left_eye[0])
    if eye_dist < 1:
        return {"yaw": 0.0, "pitch": 0.0, "pose": "front"}

    x1, y1, x2, y2 = bbox
    face_h = abs(y2 - y1)

    yaw = float((nose[0] - eye_mid[0]) / eye_dist)
    pitch = float((eye_mid[1] - nose[1]) / face_h) if face_h > 1 else 0.0

    YAW_THRESH, PITCH_THRESH = 0.18, 0.12
    if yaw > YAW_THRESH:
        pose = "right"
    elif yaw < -YAW_THRESH:
        pose = "left"
    elif pitch > PITCH_THRESH:
        pose = "up"
    elif pitch < -PITCH_THRESH:
        pose = "down"
    else:
        pose = "front"

    return {"yaw": round(yaw, 3), "pitch": round(pitch, 3), "pose": pose}


@app.get("/health")
def health():
    return {
        "ok": face_app is not None,
        "model": MODEL_NAME,
        "uptime": round(time.time() - START_TIME, 1),
    }


@app.post("/extract")
async def extract(image: UploadFile = File(...)):
    if face_app is None:
        raise HTTPException(503, "Models not ready, retry later.")

    data = await image.read()
    try:
        img_bgr = pil_to_bgr(Image.open(io.BytesIO(data)))
    except Exception as e:
        raise HTTPException(400, f"Cannot decode image: {e}")

    faces = face_app.get(img_bgr)
    if not faces:
        raise HTTPException(422, "No face detected in image.")

    face = max(faces, key=lambda f: f.det_score)
    x1, y1, x2, y2 = [round(v) for v in face.bbox]

    return JSONResponse({
        "descriptor": face.normed_embedding.tolist(),
        "confidence": round(float(face.det_score), 4),
        "pose": estimate_pose(face.kps, face.bbox),
        "box": {"x": x1, "y": y1, "width": x2 - x1, "height": y2 - y1},
    })


if __name__ == "__main__":
    import uvicorn

    uvicorn.run(app, host="127.0.0.1", port=5000, log_level="info")
