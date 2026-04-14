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

def search_course(course_id: int, query: str, top_k=20):
    """
    Search course embeddings and filter by similarity threshold.
    """
    index, metadata = load_index(course_id)
    # query_emb is already normalized by embed_text_chunks in utils.py
    query_emb = embed_text_chunks([query], prefix="query: ")[0]
    
    # Since we use IndexFlatIP and normalized vectors, distance is Cosine Similarity
    D, I = index.search(query_emb.reshape(1, -1), top_k)
    
    results = []
    for rank, idx in enumerate(I[0]):
        score = float(D[0][rank])
        
        # Un-biasing rescaling: Maps [0.80, 1.0] -> [0.0, 1.0]
        # This makes the "coincidence" percentage much more intuitive
        rescaled_score = max(0.0, min(1.0, (score - 0.80) / (1.0 - 0.80)))
        
        if idx < len(metadata) and score >= RAG_THRESHOLD:
            entry = dict(metadata[idx])
            entry["similarity"] = rescaled_score
            entry["raw_score"] = score
            entry["rank"] = len(results) + 1
            results.append(entry)
            
    return results

