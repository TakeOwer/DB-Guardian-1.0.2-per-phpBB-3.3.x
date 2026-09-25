# DB Guardian 1.0.4 per phpBB 3.3

Sostituisce l'"Errore generale" di phpBB, le pagine bianche e gli errori 500 con una pagina di servizio responsive. La pagina spiega al visitatore cosa è successo e mostra codice errore, riferimento, data e ora. Contemporaneamente l'estensione avvisa l'amministratore via e-mail, anche quando il database è completamente irraggiungibile.

## Perché non è una normale estensione

Quando MySQL non risponde, phpBB si ferma prima di caricare le estensioni. Per questo DB Guardian installa un piccolo file, il "guardiano", che PHP carica prima di phpBB tramite la direttiva `auto_prepend_file` nel file `.user.ini` della cartella del forum. Il guardiano non usa mai il database: la configurazione sta in un file e le e-mail partono con `mail()` o con il suo client SMTP.

Con PHP 8.1 e successivi, phpBB 3.3 non mostra nemmeno l'"Errore generale" quando la connessione fallisce: si ferma con un'eccezione `mysqli_sql_exception` non gestita, cioè una pagina bianca o un errore 500. Il guardiano intercetta anche questo caso.

## Cosa intercetta

- **Database non raggiungibile**: troppe connessioni (1040, 1203 max_user_connections), server spento o in riavvio (2002, 2003, 2006, 2013), credenziali errate (1045) e simili.
- **Errori SQL**: tabella mancante o danneggiata, colonna sconosciuta, lock.
- **Errori fatali PHP**: eccezioni non gestite, memoria esaurita, tempo massimo superato, anche se causati da un'estensione.
- **Altri "Errore generale" di phpBB**, facoltativo.

Il codice mostrato è per esempio `DB-1203`, `SQL-1146` o `PHP-MEMORY`. La pagina risponde con lo stato HTTP 503 e l'intestazione `Retry-After`, così i motori di ricerca capiscono che il problema è temporaneo. Si ricarica da sola con un conto alla rovescia che si può fermare, e segue la lingua del browser (italiano o inglese) e il tema chiaro o scuro.

## Avvisi e-mail

- Un'e-mail per errore, con codice, messaggio, pagina, visitatore (IP anonimizzato), e un suggerimento su cosa controllare.
- Intervallo minimo tra due avvisi per lo stesso errore (predefinito 30 minuti). L'avviso successivo dice quanti errori sono stati raggruppati. Se l'invio fallisce, ritenta dopo 5 minuti.
- Quando il forum torna raggiungibile arriva un'e-mail "Forum di nuovo raggiungibile" con la durata del guasto e il numero di richieste fallite.

## Installazione

1. Carica la cartella `salvocortesiano/dbguardian` in `ext/` del forum, ottenendo `ext/salvocortesiano/dbguardian/`.
2. ACP, Personalizza, Gestione estensioni: attiva **DB Guardian**. L'attivazione copia il guardiano in `store/dbguardian/` e aggiunge questo blocco al file `.user.ini` nella cartella del forum. Il resto del file non viene toccato; se il file non esiste, viene creato.

   ```
   ; BEGIN salvocortesiano/dbguardian - blocco gestito dall'estensione, non modificare
   auto_prepend_file = "/percorso/del/forum/store/dbguardian/guardian.php"
   ; END salvocortesiano/dbguardian
   ```

3. PHP rilegge `.user.ini` ogni 5 minuti (`user_ini.cache_ttl`). Dopo 5 minuti apri **ACP, Estensioni, DB Guardian, Stato e prove**: deve comparire "Guardiano attivo".
4. In **Impostazioni** controlla destinatari e metodo di invio. Se in phpBB usi SMTP, premi "Importa da phpBB". Poi torna in **Stato e prove** e premi "Invia e-mail di prova".
5. Sempre in **Stato e prove**, usa "Apri sul forum" per vedere la pagina di servizio servita dal vero guardiano, senza fermare nulla. Questa prova non registra errori e non invia e-mail. Finché PHP non carica il guardiano il link non è disponibile e la pagina spiega il motivo. Le anteprime "Italiano" e "Inglese" funzionano invece sempre, perché le genera il pannello di amministrazione.

### Se resta "in attesa"

Alcuni server non leggono `.user.ini`. Sui server CloudLinux con PHP LSAPI la lettura può essere disattivata dal provider; con PHP come modulo di Apache non viene mai letto. La pagina Stato e prove lo segnala e mostra la riga da aggiungere in fondo al file `.htaccess` del forum:

```
php_value auto_prepend_file "/percorso/del/forum/store/dbguardian/guardian.php"
```

Se dopo averla aggiunta il forum dà errore 500, toglila subito: quel server non accetta `php_value` e va chiesto al supporto dell'hosting di abilitare `.user.ini`.

Se nel `.user.ini` c'è già un'altra riga `auto_prepend_file`, l'estensione non la tocca e te lo segnala. Un `auto_prepend_file` impostato dal server nel `php.ini` invece continua a funzionare, perché il guardiano lo carica subito dopo di sé.

### Hosting che non permettono auto_prepend_file

Su alcuni hosting gratuiti, per esempio Altervista, `user_ini.filename` è vuoto e `php_value` non è ammesso: il guardiano non può essere caricato. La pagina Stato e prove lo segnala. Lì l'estensione serve solo per provare pannello, impostazioni, anteprime ed e-mail di prova.

### Interruttore d'emergenza

Se qualcosa non ti convince, apri `store/dbguardian/config.php` e metti `'enabled' => false`. L'effetto è immediato, senza aspettare la cache di `.user.ini`: il file resta caricato ma non fa più nulla. Non cancellare mai `store/dbguardian/guardian.php` finché il blocco nel `.user.ini` o la riga nel `.htaccess` sono presenti, altrimenti si blocca tutto il forum.

## Aggiornare DB Guardian

1. Carica i file nuovi sopra quelli vecchi, in `ext/salvocortesiano/dbguardian/`.
2. ACP, Gestione estensioni: disattiva e riattiva l'estensione, **senza** eliminare i dati. Le migrazioni aggiornano il database e le schede ACP.
3. La copia del guardiano in `store/dbguardian/` si aggiorna da sola alla prima visita di una pagina ACP di DB Guardian. In "Stato e prove", alla voce "File del guardiano", deve comparire la versione nuova con "aggiornato".
4. La riga nel `.htaccess`, se l'hai aggiunta, resta valida: il percorso del guardiano non cambia tra le versioni.

## Dopo un aggiornamento di phpBB

Il pacchetto di aggiornamento di phpBB contiene anche il `.htaccess` della cartella del forum. Se l'aggiornamento lo sovrascrive, la riga `php_value auto_prepend_file` sparisce e il guardiano non viene più caricato, senza nessun errore visibile. Dopo ogni aggiornamento di phpBB:

1. apri **Stato e prove** e controlla che dica ancora "Guardiano attivo";
2. se dice "in attesa" o "non installato", rimetti la riga in fondo al `.htaccess`.

Se usi il monitoraggio esterno, il watchdog te lo segnala da solo con l'avviso "DB Guardian non è attivo".

## Dettagli tecnici per l'amministratore

Quando il database è giù non puoi accedere come amministratore. In **Stato e prove**, "Mostra i dettagli tecnici su questo browser" imposta un cookie di 30 giorni. Con il cookie attivo, sotto la pagina di servizio vedi il messaggio completo, la query SQL, il file e la traccia. I visitatori non li vedono mai.

Tutti gli errori finiscono anche nel **Registro eventi**, con filtri per tipo. Gli errori identici ripetuti entro un minuto diventano una sola riga con il numero di ripetizioni. I file stanno in `store/dbguardian/logs/`, uno al mese, e vengono conservati per 6 mesi (modificabile). Gli errori fatali continuano ad arrivare anche nel log degli errori del server.

## Disattivazione e disinstallazione

- **Disattivando** l'estensione il blocco viene tolto da `.user.ini` e il guardiano si spegne. I file in `store/dbguardian/` restano: PHP potrebbe ancora usare per qualche minuto la vecchia copia di `.user.ini`, e se il file indicato da `auto_prepend_file` mancasse, tutto il forum andrebbe in errore.
- **Eliminando i dati** vengono cancellati anche registro e stato degli avvisi.
- Se vuoi eliminare del tutto la cartella `store/dbguardian/`, fallo a mano **almeno 5 minuti dopo** la disattivazione.
- Non modificare a mano il blocco nel `.user.ini`: usa i pulsanti della pagina Stato e prove.
- La riga `php_value auto_prepend_file` nel `.htaccess`, se l'hai aggiunta tu, **non** viene tolta dall'estensione. Finché c'è, il guardiano continua a essere caricato (spento, se l'estensione è disattivata) e i file in `store/dbguardian/` non vanno cancellati. Per disinstallare del tutto: togli prima la riga dal `.htaccess`, poi cancella la cartella.
- Se usi il watchdog su un altro server, togli prima la riga dal suo cron, poi spegni l'endpoint: in ordine inverso il watchdog segnalerebbe ogni giorno "DB Guardian non è attivo".

## Monitoraggio esterno (dalla 1.0.3)

Il guardiano gira sul server del forum: se quel server si spegne del tutto, non può avvisarti. La scheda **ACP, DB Guardian, Monitoraggio esterno** risolve questo caso con due pezzi:

- un **endpoint di stato**, `index.php?dbguardian_health=CHIAVE`, che risponde prima che phpBB si avvii. Prova la connessione a MySQL con i dati di `config.php` (timeout 3 secondi) e restituisce un breve JSON. Senza la chiave giusta la richiesta passa al forum come sempre;
- un **watchdog**: uno script Python (`watchdog/dbguardian_watchdog.py`, solo libreria standard) da far girare su un altro server, per esempio un VPS Hetzner, ogni minuto via cron.

Il watchdog avvisa via e-mail, con un server SMTP diverso da quello del forum, e/o via Telegram quando:

- il server del forum non risponde, va in timeout o ha il certificato SSL non valido;
- risponde con un errore HTTP, oppure il database non risponde;
- il forum torna raggiungibile, con la durata del guasto;
- il certificato SSL sta per scadere;
- il forum risponde ma il guardiano non è più attivo, per esempio perché un aggiornamento di phpBB ha sovrascritto il `.htaccess`.

Prima di avvisare aspetta un numero configurabile di controlli falliti di fila, così un intoppo di rete non genera falsi allarmi.

Il monitoraggio esterno **non sostituisce** il guardiano: il guardiano deve girare sul server del forum, perché è lì che intercetta gli errori e mostra la pagina di servizio. Il watchdog è uno strato in più, facoltativo. Finché non attivi l'endpoint e non avvii lo script sull'altro server, l'estensione funziona esattamente come prima.

Lo script si scarica già configurato dalla scheda ACP, che contiene anche la guida passo passo: copia sul VPS, permessi, prova con `--check` e `--test`, riga del cron. Dopo ogni modifica alle impostazioni o alla chiave va scaricato di nuovo e sostituito. In alternativa allo script, l'endpoint si può controllare con Uptime Kuma (monitor "HTTP(s) - Parola chiave", parola chiave `"ok":true`).

## Note

- Il guardiano da solo non copre il caso in cui l'intero server web è spento: lì non risponde nemmeno PHP. Per quello c'è il monitoraggio esterno descritto sopra.
- La configurazione, compresa l'eventuale password SMTP, è in `store/dbguardian/config.php`. È un file PHP con permessi 0640, protetto da `.htaccess`, come il `config.php` di phpBB.
- Se nel server `output_buffering` è attivo e in phpBB hai attivato la compressione GZip, phpBB non la avvia più perché trova un buffer in più. La compressione del server web (mod_deflate o LiteSpeed) continua a funzionare, ed è comunque quella consigliata.
- Il guardiano non interviene nell'installazione e nell'aggiornamento di phpBB (`/install/`) né da riga di comando.
- Le e-mail del guardiano e del watchdog sono sempre in italiano: partono proprio quando il database non risponde, quindi non possono leggere la lingua impostata in phpBB. La pagina di servizio invece segue la lingua del browser (italiano o inglese), e il pannello ACP la lingua dell'amministratore.

## Registro delle modifiche

**1.0.4**
- Il log amministratore di phpBB mostrava i nomi grezzi dei moduli (per esempio "Modulo aggiunto » ACP_DBGUARDIAN_MONITOR"), perché la lingua dell'estensione non era ancora caricata durante l'attivazione. Ora viene caricata prima, e una migrazione traduce le voci già scritte. Le voci delle altre estensioni non vengono toccate.
- Registro eventi: i suggerimenti "Cosa controllare" e il messaggio "Forum di nuovo online" vengono dai file di lingua (italiano e inglese).
- Plurali corretti nelle frasi con numeri ("1 minuto", "1 errore").
- Pagina di servizio in inglese: anche le etichette dei dettagli tecnici sono tradotte.
- Guida del monitoraggio esterno: segnaposto dell'indirizzo del VPS tradotto.

**1.0.3**
- Nuova scheda **Monitoraggio esterno**: endpoint di stato e watchdog Python per un altro server (e-mail con un SMTP diverso e/o Telegram), con guida passo passo e alternativa Uptime Kuma.

**1.0.2**
- "Prova dal vivo": il link non viene più offerto quando PHP non carica il guardiano (prima apriva semplicemente l'index del forum). Al suo posto compare il motivo.
- Il link della prova dal vivo punta sempre all'installazione da cui si apre l'ACP.

**1.0.1**
- Corretto l'"Errore Generale: Illegal use of $_SERVER" aprendo le anteprime. phpBB disattiva le superglobali dopo il suo avvio: il guardiano ora ne prende una copia prima di phpBB e non le legge più. Lo stesso problema avrebbe colpito anche gli errori SQL capitati a metà pagina.
- Dopo un errore fatale la pagina risponde con 503 invece del 500 impostato da PHP, e il testo grezzo dell'errore non compare più sopra la pagina di servizio.
- La copia del guardiano in `store/dbguardian/` si aggiorna da sola quando si aprono le pagine ACP.

**1.0.0**
- Prima versione: pagina di servizio al posto di "Errore generale", pagine bianche ed errori 500; avvisi e-mail con `mail()` o SMTP, raggruppati e con avviso di ripristino; registro eventi; prova dal vivo e dettagli tecnici per l'amministratore.

## Requisiti

phpBB 3.3.x, PHP 7.4 o superiore (provato fino a 8.3), PHP in modalità CGI, FastCGI, FPM o LiteSpeed.

## Licenza

GNU General Public License v2. Sviluppata da Salvo Cortesiano, https://netshadows.de, supporto: info@netshadows.de
