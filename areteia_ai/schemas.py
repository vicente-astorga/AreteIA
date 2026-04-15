from pydantic import BaseModel
from typing import List, Optional

class SuggestionItem(BaseModel):
    name: str
    why: str
    lim: str

class SuggestionsResponse(BaseModel):
    suggestions: List[SuggestionItem]

class InstrumentItem(BaseModel):
    text: str
    bloom_level: str
    points: int

class InstrumentDesign(BaseModel):
    title: str
    instructions: str
    items: List[InstrumentItem]
    justification: str

class RubricLevel(BaseModel):
    label: str
    score: int
    description: str

class RubricCriterion(BaseModel):
    name: str
    description: str
    levels: List[RubricLevel]

class RubricDesign(BaseModel):
    title: str
    criteria: List[RubricCriterion]
    justification: Optional[str] = None

class FeedbackClassification(BaseModel):
    is_valid: bool
    reason: Optional[str] = None

class GenerateRequest(BaseModel):
    course_id: int
    step: int
    objective: str = ""
    objective_json: Optional[str] = ""
    summary: str = ""
    dimensions: str = ""
    feedback: str = ""
    chosen_instrument: str = ""
    instrument_content: str = ""
    rag_context: str = ""
    d1_content: str = ""
    d3_function: str = ""
    d4_modality: str = ""
