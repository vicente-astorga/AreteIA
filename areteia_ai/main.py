from fastapi import FastAPI
from pydantic import BaseModel
import logging
import os

from rag.pipeline import run_ingestion
from rag.store import get_index_path, get_metadata_path
from rag.search import search_index, search_course
from llm import (
    generate_completion, 
    get_suggestions_prompt, 
    get_design_prompt, 
    get_rubric_prompt
)

app = FastAPI(title="AreteIA AI Service")
logging.basicConfig(level=logging.INFO)

class SyncRequest(BaseModel):
    course: dict = {}
    files: list = []

class IngestRequest(BaseModel):
    course_id: int

class SearchRequest(BaseModel):
    course_id: int
    query: str

class GenerateRequest(BaseModel):
    course_id: int
    step: int
    objective: str = ""
    summary: str = ""
    dimensions: str = ""
    chosen_instrument: str = ""
    instrument_content: str = ""
    rag_context: str = ""

@app.get("/")
async def root():
    return {"message": "AreteIA AI Service is running"}


@app.post("/sync")
async def sync_course(request: SyncRequest):
    verified = []
    for f in request.files:
        path = f.get("localpath")
        if path and os.path.exists(path):
            verified.append(f.get("filename"))
    return {"status": "success", "files_verified": len(verified)}


@app.post("/ingest")
async def ingest_course(request: IngestRequest):
    """
    Trigger ingestion for a course folder (chunking + embeddings).
    """
    if not request.course_id:
        return {"status": "error", "message": "course_id is required"}

    try:
        n_chunks = run_ingestion(request.course_id)
        status = "success" if n_chunks > 0 else "empty"
        return {
            "status": status,
            "chunks": n_chunks,
            "message": f"Ingested course {request.course_id} into {n_chunks} chunks"
        }
    except Exception as e:
        logging.exception("Error ingesting course")
        return {"status": "error", "message": str(e)}


@app.post("/search")
async def search_endpoint(request: SearchRequest):
    """
    Query a course embedding for RAG.
    """
    if not request.course_id or not request.query:
        return {"status": "error", "message": "course_id and query required"}

    try:
        results = search_course(request.course_id, request.query)
        return {"status": "success", "results": results}
    except Exception as e:
        logging.exception("Error searching course")
        return {"status": "error", "message": str(e)}


@app.post("/generate")
async def generate_endpoint(request: GenerateRequest):
    """
    Main generative endpoint for Steps 4, 5, and 6.
    """
    try:
        prompt = ""
        # 1. Fetch RAG context if not provided
        rag_text = request.rag_context
        if not rag_text and request.objective:
            results = search_course(request.course_id, request.objective)
            # Only use top 2 to save tokens
            rag_text = "\n".join([f"- {res['text']}" for res in results[:2]])

        # 2. Build prompt based on step
        if request.step == 4:
            prompt = get_suggestions_prompt(request.summary, request.objective, request.dimensions, rag_text)
        elif request.step == 5:
            prompt = get_design_prompt(request.chosen_instrument, request.objective, rag_text)
        elif request.step == 6:
            prompt = get_rubric_prompt(request.instrument_content, request.objective)
        else:
            return {"status": "error", "message": f"Step {request.step} not supported for generation"}

        # 3. Call LLM
        response_text = generate_completion(prompt)
        
        if response_text:
            return {"status": "success", "output": response_text}
        else:
            return {"status": "error", "message": "AI generation failed"}

    except Exception as e:
        logging.exception("Error in /generate")
        return {"status": "error", "message": str(e)}
    

@app.get("/status/{course_id}")
def check_status(course_id: int):
    try:
        from rag.store import get_index_path, get_metadata_path
        index_path = get_index_path(course_id)
        metadata_path = get_metadata_path(course_id)
        
        exists = os.path.exists(index_path)
        chunks = 0
        
        if exists and os.path.exists(metadata_path):
            import pickle
            with open(metadata_path, "rb") as f:
                metadata = pickle.load(f)
                chunks = len(metadata)
        else:
            exists = False

        return {
            "course_id": course_id, 
            "embedding_exists": exists, 
            "chunks": chunks,
            "path": index_path
        }
    except Exception as e:
        logging.error(f"Error checking status for course {course_id}: {e}")
        # Fallback to simple disk check if loading metadata fails
        from rag.store import get_index_path
        disk_exists = os.path.exists(get_index_path(course_id))
        return {
            "course_id": course_id, 
            "embedding_exists": disk_exists, 
            "chunks": 0, 
            "error": str(e)
        }
