# Google Sheets Database Setup

## Sheet ID (already configured)
```
1mDp_VNDl6N9RJafFQc_k9_6VCOHzuEjy6CzXS4mpgMk
```

## Step 1: Google Cloud Setup

1. https://console.cloud.google.com par jao
2. Naya project banao ya existing select karo
3. "APIs & Services" → "Library" mein jao
4. "Google Sheets API" search karo aur Enable karo
5. "APIs & Services" → "Credentials" mein jao
6. "Create Credentials" → "Service Account" click karo
7. Name do (e.g. "hms-sheets-service") aur Create karo
8. Service Account kholo → "Keys" tab → "Add Key" → "Create new key" → JSON
9. Download hogi `google-credentials.json` file
10. Is file ko `hms_backend/config/google-credentials.json` mein copy karo

## Step 2: Sheet ko Share Karo

Service Account ki email (credentials JSON mein `client_email` field) copy karo aur Google Sheet ko **Editor** permission de:
- Sheet kholo → Share button → Email paste karo → Editor → Send

## Step 3: Sheet Tabs Banao

Google Sheet mein ye tabs (worksheets) banao:

### Tab: `staff`
Headers (Row 1):
```
id | name | email | password | mobile | role | description | soft_delete | created_at | updated_at
```

### Tab: `doctors`
```
id | staff_id | specialization | qualification | experience_years | created_at | updated_at
```

### Tab: `patients`
```
id | unique_id | name | email | mobile | age | address | city | state | pincode | symptoms | added_by | relative_id | diagnosis_id | product_id | soft_delete | created_at | updated_at
```

### Tab: `products`
```
id | product_name | description | price | stock_quantity | soft_delete | created_at | updated_at
```

### Tab: `diagnoses`
```
id | diagnosis_name | description | status | soft_delete | created_at | updated_at
```

### Tab: `visits`
```
id | patient_id | doctor_id | diagnosis_id | product_id | visit_date | created_at
```

### Tab: `password_resets`
```
id | email | account_type | reset_token | expires_at | used | created_at
```

### Tab: `settings`
```
id | setting_key | setting_value | created_at | updated_at
```

## Step 4: Admin User Banao

`staff` sheet mein Row 2 mein manually add karo:
```
1 | Admin User | admin@hospital.com | admin123 | 9999999999 | doctor | Hospital Admin | 0 | 2026-01-01 00:00:00 | 2026-01-01 00:00:00
```

## Notes
- `soft_delete` = 0 means active, 1 means deleted
- `role` = `doctor` (admin privileges) ya `staff`
- Password is stored as plain text in this demo (production mein hash karein)
