"""Sample data — one example of every supported question/field type.
Used by:
  - /admin/quizzes/demo  → creates a demo quiz pre-populated with all of these
  - /admin/templates/all-types.json  → downloadable template
  - importers.parse_json  → bulk-import format
"""

# Demo FORM with all collection-style fields
FORM_TEMPLATE = {
    "title": "Demo Registration Form (all field types)",
    "kind": "form",
    "description": "An example showing every form field supported by QuizForge. Edit, duplicate or delete the fields you don't need.",
    "questions": [
        {"type": "section_break", "text": "Personal information",
         "explanation": "Please fill in your contact details below.", "is_required": False},
        {"type": "full_name", "text": "Your full name", "is_required": True},
        {"type": "email", "text": "Email address", "is_required": True},
        {"type": "phone", "text": "Phone number", "is_required": False},
        {"type": "date", "text": "Date of birth", "is_required": False},
        {"type": "address", "text": "Mailing address", "is_required": False},

        {"type": "section_break", "text": "About you",
         "explanation": "Tell us a bit about yourself."},
        {"type": "dropdown", "text": "Country", "options": ["USA", "UK", "Pakistan", "India", "UAE", "Canada", "Other"], "is_required": True},
        {"type": "mcq_single", "text": "How did you hear about us?",
         "options": ["Google search", "Friend / colleague", "Social media", "Conference", "Other"], "correct_answers": [], "is_required": False},
        {"type": "mcq_multi", "text": "Which features interest you most?",
         "options": ["Online exams", "Live polls", "Certificates", "Anti-cheating", "AI quiz generation"], "correct_answers": [], "is_required": False},
        {"type": "rating", "text": "How would you rate our marketing site?", "is_required": False},
        {"type": "nps", "text": "How likely are you to recommend us?", "is_required": False},
        {"type": "slider", "text": "How many people on your team?",
         "options": [], "correct_answers": [1, 1000], "is_required": False},

        {"type": "section_break", "text": "More details",
         "explanation": "Optional extras."},
        {"type": "url", "text": "Your website or LinkedIn URL", "is_required": False},
        {"type": "time", "text": "Preferred time to be contacted", "is_required": False},
        {"type": "datetime", "text": "Preferred date and time for a call", "is_required": False},
        {"type": "number", "text": "Annual training budget (USD)", "is_required": False},
        {"type": "true_false", "text": "Do you currently use an LMS?", "is_required": False},
        {"type": "long_answer", "text": "Anything else you'd like us to know?", "is_required": False},
        {"type": "file_upload", "text": "Upload a brief or document (optional)", "is_required": False},
        {"type": "signature", "text": "Sign to confirm your details are accurate", "is_required": True},
        {"type": "consent", "text": "Consent",
         "explanation": "I agree to the terms of service and privacy policy.",
         "is_required": True},
    ],
}


# Demo EXAM showcasing graded + interactive types
EXAM_TEMPLATE = {
    "title": "Demo Exam (graded + interactive types)",
    "kind": "exam",
    "description": "An example exam showing scored question types. Pass mark 60%.",
    "pass_mark": 60,
    "show_correct_answers": True,
    "questions": [
        {"type": "mcq_single", "text": "What is the capital of France?",
         "options": ["Berlin", "Paris", "Tokyo", "Rome"], "correct_answers": [1], "points": 1, "is_required": True,
         "explanation": "Paris has been the capital of France since the 10th century."},
        {"type": "mcq_multi", "text": "Which of these are prime numbers?",
         "options": ["2", "3", "4", "5", "9"], "correct_answers": [0, 1, 3], "points": 2, "is_required": True},
        {"type": "true_false", "text": "The sun is a star.",
         "options": ["True", "False"], "correct_answers": [0], "points": 1, "is_required": True},
        {"type": "short_answer", "text": "What does HTTP stand for?",
         "options": [], "correct_answers": ["Hypertext Transfer Protocol"], "points": 1, "is_required": True},
        {"type": "fill_blank", "text": "Water boils at ___ degrees Celsius at sea level.",
         "options": [], "correct_answers": ["100"], "points": 1, "is_required": True},
        {"type": "matching", "text": "Match each country to its capital.",
         "options": [{"a": "France", "b": "Paris"}, {"a": "Japan", "b": "Tokyo"},
                     {"a": "Egypt", "b": "Cairo"}, {"a": "Brazil", "b": "Brasília"}],
         "correct_answers": [], "points": 4, "is_required": True},
        {"type": "ordering", "text": "Arrange the planets in order from the sun.",
         "options": ["Mercury", "Venus", "Earth", "Mars"],
         "correct_answers": [0, 1, 2, 3], "points": 2, "is_required": True},
        {"type": "drag_drop", "text": "Categorize these animals.",
         "options": [{"item": "Lion", "bin": "Mammal"}, {"item": "Eagle", "bin": "Bird"},
                     {"item": "Shark", "bin": "Fish"}, {"item": "Frog", "bin": "Amphibian"}],
         "correct_answers": [], "points": 4, "is_required": True},
        {"type": "long_answer", "text": "Briefly explain photosynthesis (manually graded).",
         "options": [], "correct_answers": [], "points": 3, "is_required": False,
         "explanation": "Answer should mention sunlight, CO₂, water → glucose + O₂."},
    ],
}


# Demo POLL with NPS + open ended + word cloud
POLL_TEMPLATE = {
    "title": "Demo Audience Poll",
    "kind": "poll",
    "description": "Real-time opinion poll example.",
    "questions": [
        {"type": "mcq_single", "text": "What's your favorite season?",
         "options": ["Spring", "Summer", "Autumn", "Winter"], "correct_answers": [], "points": 0},
        {"type": "rating", "text": "Rate today's session", "is_required": False},
        {"type": "nps", "text": "How likely are you to attend next year?", "is_required": False},
        {"type": "word_cloud", "text": "One word to describe how you feel right now",
         "options": [], "correct_answers": []},
        {"type": "open_ended", "text": "Any suggestions or feedback?",
         "options": [], "correct_answers": [], "is_required": False},
    ],
}


ALL_TEMPLATES = {
    "form": FORM_TEMPLATE,
    "exam": EXAM_TEMPLATE,
    "poll": POLL_TEMPLATE,
}
