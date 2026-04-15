# AI Alt Text Generator

WordPress plugin που παράγει αυτόματα alt text για εικόνες χρησιμοποιώντας OpenAI Vision (GPT-4o mini / GPT-4o).

## Τι κάνει

Αναλύει κάθε εικόνα της βιβλιοθήκης μέσων (Media Library) και παράγει περιγραφικό alt text, χρήσιμο για:
- **SEO** — οι μηχανές αναζήτησης κατανοούν το περιεχόμενο των εικόνων
- **Προσβασιμότητα** — screen readers διαβάζουν το alt text σε χρήστες με προβλήματα όρασης
- **WCAG compliance** — κάλυψη βασικών προτύπων προσβασιμότητας

## Απαιτήσεις

- WordPress 6.0+
- PHP 8.0+ με `openssl` extension
- OpenAI API key (από [platform.openai.com/api-keys](https://platform.openai.com/api-keys))

## Εγκατάσταση

1. Ανέβασε τον φάκελο `ai-alt-text` στο `/wp-content/plugins/`
2. Ενεργοποίησε το plugin από **Plugins → Installed Plugins**
3. Πήγαινε **Media → AI Alt Text** και βάλε το OpenAI API key σου

## Λειτουργίες

### Ρυθμίσεις

| Ρύθμιση | Τιμές | Περιγραφή |
|---------|-------|-----------|
| API Key | `sk-...` | OpenAI API key — αποθηκεύεται κρυπτογραφημένο |
| Μοντέλο | `gpt-4o-mini` / `gpt-4o` | Το μοντέλο που χρησιμοποιείται για ανάλυση εικόνων |
| Γλώσσα | Ελληνικά / English | Γλώσσα παραγωγής alt text |
| Batch size | 1–20 | Πόσες εικόνες επεξεργάζονται σε κάθε κύκλο |
| Αντικατάσταση | on/off | Αν ενεργό, αντικαθιστά υπάρχοντα alt texts |

### Bulk Generation

- Μετρά πόσες εικόνες δεν έχουν alt text
- Επεξεργάζεται τις εικόνες σε batches για να αποφύγει timeouts
- Live progress bar με ποσοστό ολοκλήρωσης
- Log σε πραγματικό χρόνο (επιτυχία / αποτύχια ανά εικόνα)
- Κουμπί διακοπής που σταματά στο τέλος του τρέχοντος batch

### Test Key

Ελέγχει αν το API key είναι έγκυρο κάνοντας ένα δωρεάν call στο OpenAI `/v1/models` endpoint — δεν ξοδεύει credits.

### Διαγραφή Key

Αφαιρεί το API key από τη βάση δεδομένων χωρίς να επηρεάζει τα υπόλοιπα δεδομένα.

## Ασφάλεια API Key

Το API key **δεν αποθηκεύεται ποτέ ως plaintext** στη βάση δεδομένων.

### Πώς λειτουργεί η κρυπτογράφηση

1. **Αλγόριθμος:** AES-256-CBC
2. **Encryption key:** Παράγεται από το `AUTH_KEY` του `wp-config.php` μέσω SHA-256 — είναι μοναδικό ανά WordPress installation και δεν αποθηκεύεται στη βάση
3. **IV (Initialization Vector):** Τυχαίο για κάθε αποθήκευση — αποτρέπει επαναχρησιμοποίηση του ίδιου ciphertext
4. **Format αποθήκευσης:** `base64( iv :: encrypted_key )` στο WordPress option `aatg_settings`

**Αποτέλεσμα:** Ακόμα και αν κάποιος αποκτήσει πρόσβαση στη βάση δεδομένων, το API key δεν μπορεί να αποκρυπτογραφηθεί χωρίς πρόσβαση στο `wp-config.php`.

### Τι φαίνεται στο admin UI

- Το plaintext key **δεν εμφανίζεται ποτέ** στη φόρμα ρυθμίσεων
- Αν υπάρχει key, εμφανίζεται μόνο "✓ API key είναι ρυθμισμένο"
- Άφησε το πεδίο κενό κατά την αποθήκευση για να διατηρηθεί το υπάρχον key

### Άλλες προστασίες

- **Capability check:** Μόνο χρήστες με `manage_options` (Administrators) έχουν πρόσβαση
- **Nonce verification:** Κάθε form submit και AJAX call επαληθεύεται με WordPress nonce
- **Model whitelist:** Το μοντέλο OpenAI επαληθεύεται server-side — ποτέ user input απευθείας στο API
- **Output sanitization:** Κάθε απάντηση από το OpenAI sanitize-αρεται πριν αποθηκευτεί

## Κόστος χρήσης

| Μοντέλο | Κόστος ανά εικόνα (εκτίμηση) |
|---------|-------------------------------|
| GPT-4o mini | ~$0.001 |
| GPT-4o | ~$0.01 |

Οι εικόνες αναλύονται με `detail: low` για μείωση κόστους — επαρκές για alt text.

## Δομή αρχείων

```
ai-alt-text/
├── ai-alt-text-generator.php   # Main plugin file, constants, activation hook
├── uninstall.php               # Διαγράφει option από DB κατά την απεγκατάσταση
├── README.md
└── includes/
    ├── security.php            # Capability checks, nonce verification, encrypt/decrypt, settings CRUD
    ├── openai.php              # OpenAI API call — αναλύει εικόνα και επιστρέφει alt text
    ├── ajax.php                # AJAX handlers: bulk generate, count, test key, delete key
    └── admin.php               # Admin page UI και settings form
```

## Απεγκατάσταση

Κατά τη διαγραφή του plugin (Delete από το WordPress), διαγράφεται **μόνο** το option `aatg_settings` (API key + ρυθμίσεις).

Τα alt texts που έχουν παραχθεί **παραμένουν** — αποθηκεύονται ως `_wp_attachment_image_alt` post meta στα attachments και δεν εξαρτώνται από το plugin.

## Changelog

### 1.1.0
- Κρυπτογράφηση API key με AES-256-CBC
- Κουμπί διαγραφής API key
- Το key field δεν εκθέτει ποτέ plaintext στο HTML

### 1.0.0
- Αρχική έκδοση
- Bulk generation με progress bar και live log
- Υποστήριξη GPT-4o mini και GPT-4o
- Ελληνικά / English alt text
