from fastapi import FastAPI, BackgroundTasks
from pydantic import BaseModel
import logging
import os

from rag.store import get_index_path, get_metadata_path
# Heavy imports moved inside endpoints to avoid blocking startup


app = FastAPI(title="AreteIA AI Service")
logging.basicConfig(level=logging.INFO)

@app.on_event("startup")
async def startup_event():
    # Warm up the embedding model
    from rag.utils import get_model
    logging.info("Warming up embedding model (FastEmbed)...")
    get_model()
    logging.info("Model ready.")

class SyncRequest(BaseModel):
    course: dict = {}
    files: list = []

# Thread-safe (mostly) dictionary to track background task progress
INGESTION_PROGRESS = {}

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
async def ingest_course(request: IngestRequest, background_tasks: BackgroundTasks):
    """
    Trigger ingestion for a course folder in the background.
    """
    if not request.course_id:
        return {"status": "error", "message": "course_id is required"}

    from rag.pipeline import run_ingestion
    
    # Initialize progress
    INGESTION_PROGRESS[request.course_id] = {"progress": 0, "message": "Iniciando..."}
    
    def progress_callback(val, msg):
        INGESTION_PROGRESS[request.course_id] = {"progress": val, "message": msg}

    background_tasks.add_task(run_ingestion, request.course_id, progress_callback=progress_callback)
    return {
        "status": "started",
        "message": f"Ingestion triggered in background for course {request.course_id}"
    }

@app.post("/search")
async def search_endpoint(request: SearchRequest):
    """
    Query a course embedding for RAG.
    """
    if not request.course_id or not request.query:
        return {"status": "error", "message": "course_id and query required"}

    from rag.search import search_course
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
        from llm import (
            generate_completion, 
            get_suggestions_prompt, 
            get_design_prompt, 
            get_rubric_prompt
        )
        from rag.search import search_course

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
        
        # 1. Check active background progress first
        if course_id in INGESTION_PROGRESS:
            return {"status": "success", "data": INGESTION_PROGRESS[course_id]}
            
        return {
            "status": "success",
            "data": {
                "progress": 100 if exists else 0,
                "message": "Completado" if exists else "Pendiente",
                "embedding_exists": exists,
                "chunks": chunks
            }
        }
    except Exception as e:
        return {"status": "error", "message": str(e)}


@app.delete("/ingest/{course_id}")
async def delete_embeddings(course_id: int):
    """
    Delete the RAG index and metadata for a course.
    """
    from rag.store import get_index_path, get_metadata_path
    import os
    index = get_index_path(course_id)
    meta = get_metadata_path(course_id)
    if os.path.exists(index):
        os.remove(index)
    if os.path.exists(meta):
        os.remove(meta)
    return {"status": "success", "message": "Embeddings deleted"}
