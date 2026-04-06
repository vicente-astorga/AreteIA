from fastapi import FastAPI, Request
import logging
import os

from rag.pipeline import run_ingestion
from rag.store import get_index_path, get_metadata_path
from rag.search import search_index, search_course

app = FastAPI(title="AreteIA AI Service")
logging.basicConfig(level=logging.INFO)


@app.get("/")
async def root():
    return {"message": "AreteIA AI Service is running"}


@app.post("/sync")
async def sync_course(request: Request):
    data = await request.json()
    course = data.get("course", {})
    files = data.get("files", [])
    verified = []
    for f in files:
        path = f.get("localpath")
        if path and os.path.exists(path):
            verified.append(f.get("filename"))
    return {"status": "success", "files_verified": len(verified)}

# @app.post("/ingest")
# async def ingest_course(request: Request):
#     data = await request.json()
#     course_id = data.get("course_id")
#     if not course_id:
#         return {"status": "error", "message": "Missing course_id"}
#     if os.path.exists(get_index_path(course_id)):
#         return {"status": "exists", "message": f"Embeddings already exist for course {course_id}"}
#     return run_ingestion(course_id)

# @app.post("/ingest")
# async def ingest_course(request: Request):
#     data = await request.json()
#     course_id = data.get("course_id")
#     if not course_id:
#         return {"status": "error", "message": "Missing course_id"}
#     # 🔥 delete existing embeddings 2
#     index_path = get_index_path(course_id)
#     metadata_path = get_metadata_path(course_id)
#     if os.path.exists(index_path):
#         os.remove(index_path)
#     if os.path.exists(metadata_path):
#         os.remove(metadata_path)

#     return run_ingestion(course_id)

@app.post("/ingest")
async def ingest_course(request: Request):
    """
    Trigger ingestion for a course folder (chunking + embeddings).
    Expects JSON: {"course_id": 2, "folder_path": "/var/www/moodledata/areteia_sync"}
    """
    data = await request.json()
    course_id = data.get("course_id")
    if not course_id:
        return {"status": "error", "message": "course_id is required"}

    try:
        n_chunks = run_ingestion(course_id)
        return {
            "status": "success",
            "message": f"Ingested course {course_id} into {n_chunks} chunks"
        }
    except Exception as e:
        logging.exception("Error ingesting course")
        return {"status": "error", "message": str(e)}

# @app.post("/search")
# async def search(request: Request):
#     data = await request.json()
#     course_id = data.get("course_id")
#     query = data.get("query")
#     if not course_id or not query:
#         return {"status": "error", "message": "Missing course_id or query"}
#     results = search_index(course_id, query)
#     return {"status": "success", "results": results}

@app.post("/search")
async def search_endpoint(request: Request):
    """
    Query a course embedding for RAG.
    Expects JSON: {"course_id": 2, "query": "text to search"}
    """
    data = await request.json()
    course_id = data.get("course_id")
    query = data.get("query")
    if not course_id or not query:
        return {"status": "error", "message": "course_id and query required"}

    try:
        results = search_course(course_id, query)
        return {"status": "success", "results": results}
    except Exception as e:
        logging.exception("Error searching course")
        return {"status": "error", "message": str(e)}
    
@app.get("/status/{course_id}")
def check_status(course_id: int):
    exists = os.path.exists(get_index_path(course_id))
    return {"course_id": course_id, "embedding_exists": exists}
