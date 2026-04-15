import faiss
import pickle
import numpy as np
import os
from pathlib import Path

from rag.embed import embed_texts
from rag.store import get_index_path, get_metadata_path, save_index, load_index
from rag.utils import extract_pdf, extract_docx, extract_pptx, embed_text_chunks

from langchain.text_splitter import RecursiveCharacterTextSplitter

def search_index(course_id, query, k=5):
    index_path = get_index_path(course_id)
    metadata_path = get_metadata_path(course_id)

    # Load index
    index = faiss.read_index(index_path)

    # Load metadata
    with open(metadata_path, "rb") as f:
        metadata = pickle.load(f)

    # Embed query
    query_vector = embed_texts([query])
    query_vector = np.array(query_vector).astype("float32")

    # Search
    distances, indices = index.search(query_vector, k)

    results = []
    for i in indices[0]:
        if i < len(metadata):
            results.append(metadata[i])

    return results


RAG_THRESHOLD = float(os.getenv("RAG_THRESHOLD", 0.82))

def search_course(course_id: int, query: str, top_k=10):
    """
    Search course embeddings and filter by similarity threshold.
    """
    query_emb = embed_text_chunks([query], prefix="query: ")[0]
    return search_course_by_vector(course_id, query_emb, top_k)

def search_course_by_vector(course_id: int, query_vector: np.ndarray, top_k=10):
    """
    Perform search using a pre-computed vector. 
    Useful for batch processing.
    """
    try:
        index, metadata = load_index(course_id)
    except Exception as e:
        import logging
        logging.warning(f"Could not load index for course {course_id}: {e}")
        return []

    if len(query_vector.shape) == 1:
        query_vector = query_vector.reshape(1, -1)
        
    D, I = index.search(query_vector, top_k)
    
    results = []
    for rank, idx in enumerate(I[0]):
        score = float(D[0][rank])
        rescaled_score = max(0.0, min(1.0, (score - 0.80) / (1.0 - 0.80)))
        
        if idx < len(metadata) and score >= RAG_THRESHOLD:
            entry = dict(metadata[idx])
            entry["similarity"] = rescaled_score
            entry["raw_score"] = score
            entry["rank"] = len(results) + 1
            results.append(entry)
            
    return results

def search_guidelines(query: str, top_k=5):
    """
    Search specifically in the pedagogical guidelines (course_id=0).
    """
    try:
        return search_course(0, query, top_k=top_k)
    except Exception as e:
        import logging
        logging.warning(f"Guidelines index not found or error: {e}")
        return []

