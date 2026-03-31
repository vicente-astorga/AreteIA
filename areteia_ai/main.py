from fastapi import FastAPI, Request
import logging

import os

app = FastAPI(title="AreteIA AI Service")
logging.basicConfig(level=logging.INFO)

@app.get("/")
async def root():
    return {"message": "AreteIA AI Service is running"}

@app.post("/sync")
async def sync_course(request: Request):
    try:
        body = await request.body()
        logging.info(f"Raw body received: {body.decode('utf-8')[:500]}...")
        
        data = await request.json()
        course_name = data.get("course", {}).get("fullname", "Unknown")
        files = data.get("files", [])
        
        verified_files = []
        for f in files:
            localpath = f.get("localpath")
            if localpath and os.path.exists(localpath):
                size = os.path.getsize(localpath)
                verified_files.append(f"{f.get('filename')} ({size} bytes)")
            else:
                logging.warning(f"File not found on disk: {localpath}")
        
        logging.info(f"Received sync for course: {course_name}")
        logging.info(f"Verified {len(verified_files)} files physically on disk")
        
        return {
            "status": "success",
            "message": f"Data for '{course_name}' received. {len(verified_files)} files ready for RAG.",
            "info": {
                "files_found": verified_files
            }
        }
    except Exception as e:
        logging.error(f"Error processing sync: {str(e)}")
        return {"status": "error", "message": str(e)}

if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="0.0.0.0", port=8000)
