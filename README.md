# DB Guardian 1.0.2 per phpBB 3.3

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
5. Sempre in **Stato e prove**, usa "Apri sul forum" per vedere la pagina di servizio servita dal vero guardiano, senza fermare nulla. Questa prova non registra errori e non invia e-mail.

### Se resta "in attesa"

Alcuni server non leggono `.user.ini`. Sui server CloudLinux con PHP LSAPI la lettura può essere disattivata dal provider; con PHP come modulo di Apache non viene mai letto. La pagina Stato e prove lo segnala e mostra la riga da aggiungere in fondo al file `.htaccess` del forum:

```
php_value auto_prepend_file "/percorso/del/forum/store/dbguardian/guardian.php"
```

Se dopo averla aggiunta il forum dà errore 500, toglila subito: quel server non accetta `php_value` e va chiesto al supporto dell'hosting di abilitare `.user.ini`.

Se nel `.user.ini` c'è già un'altra riga `auto_prepend_file`, l'estensione non la tocca e te lo segnala. Un `auto_prepend_file` impostato dal server nel `php.ini` invece continua a funzionare, perché il guardiano lo carica subito dopo di sé.

## Dettagli tecnici per l'amministratore

Quando il database è giù non puoi accedere come amministratore. In **Stato e prove**, "Mostra i dettagli tecnici su questo browser" imposta un cookie di 30 giorni. Con il cookie attivo, sotto la pagina di servizio vedi il messaggio completo, la query SQL, il file e la traccia. I visitatori non li vedono mai.

Tutti gli errori finiscono anche nel **Registro eventi**, con filtri per tipo. Gli errori identici ripetuti entro un minuto diventano una sola riga con il numero di ripetizioni. I file stanno in `store/dbguardian/logs/`, uno al mese, e vengono conservati per 6 mesi (modificabile). Gli errori fatali continuano ad arrivare anche nel log degli errori del server.

## Disattivazione e disinstallazione

- **Disattivando** l'estensione il blocco viene tolto da `.user.ini` e il guardiano si spegne. I file in `store/dbguardian/` restano: PHP potrebbe ancora usare per qualche minuto la vecchia copia di `.user.ini`, e se il file indicato da `auto_prepend_file` mancasse, tutto il forum andrebbe in errore.
- **Eliminando i dati** vengono cancellati anche registro e stato degli avvisi.
- Se vuoi eliminare del tutto la cartella `store/dbguardian/`, fallo a mano **almeno 5 minuti dopo** la disattivazione.
- Non modificare a mano il blocco nel `.user.ini`: usa i pulsanti della pagina Stato e prove.

## Note

- Il guardiano non copre il caso in cui l'intero server web è spento: lì non risponde nemmeno PHP. Per quello serve un controllo esterno, per esempio Uptime Kuma sul VPS Hetzner, che puoi indicare come "Pagina di stato" nelle impostazioni.
- La configurazione, compresa l'eventuale password SMTP, è in `store/dbguardian/config.php`. È un file PHP con permessi 0640, protetto da `.htaccess`, come il `config.php` di phpBB.
- Se nel server `output_buffering` è attivo e in phpBB hai attivato la compressione GZip, phpBB non la avvia più perché trova un buffer in più. La compressione del server web (mod_deflate o LiteSpeed) continua a funzionare, ed è comunque quella consigliata.
- Il guardiano non interviene nell'installazione e nell'aggiornamento di phpBB (`/install/`) né da riga di comando.

## Requisiti

phpBB 3.3.x, PHP 7.4 o superiore (provato fino a 8.3), PHP in modalità CGI, FastCGI, FPM o LiteSpeed.

## Licenza

GNU General Public License v2. Sviluppata da Salvo Cortesiano, https://netshadows.de, supporto: info@netshadows.de
