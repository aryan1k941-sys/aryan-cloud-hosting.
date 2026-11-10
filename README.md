# Aryan Cloud Hosting — Render Free Demo

## Is ZIP mein kya kaam karta hai?
- PHP 8.2 + SQLite website (database first request par create hota hai)
- Registration, login, logout, CSRF-protected forms
- Admin dashboard, users, plans, gateway/settings forms
- Python project ZIP upload (20 MB limit), safe path checks, `main.py` required
- User dashboard and order history
- TailwindCSS CDN UI

## Zaroori sachchai: Render Free ki limitations
Yeh **free demo/control panel** hai, production multi-tenant Python hosting nahi.
Render Free web services sleep/ephemeral filesystem limitations ke saath aati hain.
SQLite aur uploaded ZIPs redeploy/restart ke baad lose ho sakte hain. Render Free par
persistent disk available nahi. Free web service ko 24/7 Python bot worker samajhna
sahi nahi hoga.

Is build mein uploaded customer Python code execute nahi hota. Arbitrary uploaded
Python ko PHP web process/container ke andar run karna multi-user platform par
remote-code-execution risk hai. Real Start/Stop/Logs ke liye alag isolated runner,
per-bot OS/container isolation, CPU/RAM/PID/time limits, network policy, quotas,
and persistent storage implement aur security-test karna hoga.

## GitHub + Render setup (phone se bhi)
1. GitHub par `aryan-cloud-hosting` naam ka **Public ya Private** repository banao.
2. Is ZIP ko extract karo.
3. `index.php`, `Dockerfile`, `render.yaml`, `.dockerignore`, `README.md` ko repository ke root mein upload karo.
4. Render Dashboard → New → Blueprint (agar render.yaml detect karwana hai) → GitHub repo select → Apply.
   Ya New → Web Service → repo select → Runtime Docker → deploy.
5. Deploy complete hone par Render URL open karo.
6. Register page se normal user banao.
7. Admin login:
   - Email: `armanmalikh762@gmail.com`
   - Initial password: `aryanyt321`
8. First login ke baad admin password change karna is demo mein abhi available nahi; isliye public production use se pehle admin password environment-based secret se configure karna aur password-change feature add karna zaroori hai.

## Important: SQLite persistence
Render Free service par app ka local filesystem persistent nahi hota. Free plan ko sirf UI/flow testing ke liye use karo. Real customer accounts/orders/files ke liye persistent disk (eligible paid service) ya managed database/object storage configure karo. SQLite ko multiple replicas ke saath use na karo.

## ZIP upload test
ZIP ke andar `main.py` hona chahiye. Optional `requirements.txt` rakh sakte ho. Dashboard par ZIP upload hoga aur bot record `uploaded` state mein dikhega. Yeh demo execution nahi karta.

## Payments
SGVIP credentials fields admin panel mein save hote hain, lekin payment checkout aur webhook jaan-boojhkar disabled hain. User ke diye API shape ko live payment implementation samajhna safe nahi: exact merchant docs, signature format, callback retries, amount/reference verification, idempotency, and test transaction verify karna hoga. Payment success ko kabhi browser redirect se trust mat karna.

## Security before production
- Initial admin password turant rotate/replace karo.
- HTTPS enable karo.
- Upload size/MIME/ZIP-bomb checks aur malware scanning add karo.
- Uploaded Python ko web container mein kabhi execute na karo.
- Runner ko private network + strong token + per-user isolation ke saath deploy karo.
- Rate limiting, email verification, backups, audit logs, quotas, and abuse reporting add karo.
