import faiss
import os
import pickle

BASE_PATH = os.getenv("ARETEIA_SYNC_PATH", "/var/www/moodledata/areteia_sync")


def get_course_dir(course_id):
    return f"{BASE_PATH}/course_{course_id}"

def get_index_path(course_id):
    return f"{get_course_dir(course_id)}/index.faiss"

def get_metadata_path(course_id):
    return f"{get_course_dir(course_id)}/metadata.pkl"

def save_index(course_id, embeddings, metadata):
    dim = len(embeddings[0])
    index = faiss.IndexFlatL2(dim)
    index.add(embeddings)
    course_dir = get_course_dir(course_id)
    os.makedirs(course_dir, exist_ok=True)
    faiss.write_index(index, get_index_path(course_id))
    with open(get_metadata_path(course_id), "wb") as f:
        pickle.dump(metadata, f)

def load_index(course_id):
    index = faiss.read_index(get_index_path(course_id))

    with open(get_metadata_path(course_id), "rb") as f:
        metadata = pickle.load(f)

    return index, metadata
