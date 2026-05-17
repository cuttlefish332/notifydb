curl -X POST http://127.0.0.1:8000/api/events \
  -H "Authorization: Bearer YOUR_PROJECT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "tableName": "users",
    "recordId": "42",
    "eventType": "updated",
    "oldValues": {"email": "old@example.com"},
    "newValues": {"email": "new@example.com"}
  }'
