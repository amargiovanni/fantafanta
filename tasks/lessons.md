
## 2026-08-07 — Il tetto estrazioni non reggeva: assunzione sul driver cache
- **Cosa è andato storto**: `ExtractionGate` assumeva la semantica Redis di `Cache::increment` (chiave assente → creata a 1). Col driver reale (`CACHE_STORE=database`) `increment` su chiave assente ritorna `false` senza crearla; `false <= cap` è sempre vero → tetto mai applicato → 130 run reali di claude non voluti durante un full scrape.
- **Regola**: prima di usare una primitiva Cache/Queue/Lock il cui comportamento varia per driver (increment, add, lock, sadd), verificare il driver configurato in `.env`/config e coprire con un test che gira sul driver di produzione, non su array/Redis.
- **In generale**: ogni guardia che protegge SPESA REALE (denaro, rate limit, run AI) ha un test che dimostra il blocco oltre soglia, non solo il passaggio sotto soglia.
