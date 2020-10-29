# Wtyczka BillTech payments dla LMS

## Opis
Wtyczka umożliwia integrację z usługą BillTech Pay poprzez:
* Dodanie przycisku *Opłać teraz* do panelu klienta w sekcji finanse przy saldzie oraz indywidualnych 
fakturach pozwalając na wykonanie płatności on-line poprzez platformę BillTech,
* Pozwala na dodanie przycisku *Opłać teraz* do emaili z fakturą oraz notyfikacji,
* Wstrzykuje informacje o płatności do nagłówków wiadomości email z fakturą,
* Dodaje przycisk *Opłać teraz* do ekranu blokady internetu,
* Informacja o płatności wykonanej na platformie BillTech trafia do LMS.

BillTech Pay to usługa, która pozwala Dostawcom usług na wygodne pobieranie należności od swoich klientów. 
Po wystawieniu faktury Dostawca generuje link do płatności, który może dostarczyć swoim klientom różnymi kanałami,
 np. wysłać w wiadomości e-mail, sms lub pokazać w panelu online. 
Klient (użytkownik) po kliknięciu w taki link, zostaje przekierowany na ekran podsumowania płatności.
Informacja o wykonanej płatności natychmiast trafia do Dostawcy,
 dzięki czemu możliwe jest szybkie zwiększenia salda klienta oraz ew. zdjęcie blokady usług.

#### Uwaga
Wtyczka do działania wymaga aktualizacji odpowiedniej wersji LMS. W przypadku posiadania najnowszej wersji
lmsgit nie jest konieczne dodatkowe działanie. W przeciwnym wypadku zapraszamy do kontaktu, chętnie pomożemy 
z wprowadzeniem odpowiednich zmian również do innych wersji LMS.

## Instalacja
* Umieść zawartość tego repozytorium w katalogu *plugins/BillTech* w katalogu instalacyjnym LMSa,
* Zaloguj się do panelu admina LMS,
* Przejdź do zakładki *Konfiguracja -> Wtyczki*,
* Kliknij żarówkę po prawej stronie w wierszu z wtyczką BillTech aby ją włączyć,
* W szablonie wiadomości email z powiadomieniem o wystawieniu nowej faktury dodaj `%billtech_btn` i/lub `%billtech_balance_btn`,
w miejscu, w którym powinny pojawić się przyciski do opłacenia odpowiednio indywidualnej faktury i/lub salda. 

## Konfiguracja
W panelu admina wejdź w zakładkę *Konfiguracja -> BillTech* i wpisz wartości zmiennych konfiguracyjnych otrzymanych od <admin@billtech.pl>. 
Podane wartości można również wprowadzić w panelu zakładce *Konfiguracja -> Interfejs użytkownika* w sekcji billtech.

## Dodatkowe informacje
### Obsługa płatności po stronie klienta
Wpłaty które powstają po wykonaniu płatności BillTech, to tzw. opłaty tymczasowe. Są tworzone aby użytkownik widział wykonaną opłatę w userpanelu. Wpłaty tymczasowe również umożliwiają natychmiastowe odblokowanie usług w przypadku blokady z powodu niepłacenia. Opłaty tymczasowe przestają być potrzebne w momencie pojawienia się opłat z banku, wtedy mogą zostać zamknięte, po czym przestają być widoczne w panelu admina. Istnieją 3 możliwości ich zamykania:

   1. Po upływie zadanej liczby dni (domyślnie jest to 5 dni). Odpowiada za to zmienna środowiskowa billtech.payment_expiration. 
    Można ją ustawić również na 0, wtedy opłaty tymczasowe nie wygasają po upływie czasu. 
   
   1. Są zamykane automatycznie w momencie dokonania cashimport-u. Aby włączyć rozliczanie poprzez cashimport we wtyczce, należy ustawić zmienną billtech.cashimport_enabled na wartość true. Ponadto ważne jest aby w pliku, który jest importowany były numery referencyjne wpłat, zawarte w tytułach przelewów. Aby wpłata z się zamknęła, w importowanym pliku powinien być wpis o numerze referencyjnym (przykładowo 20201110-123456). 
    
   1. Można je zamykać manualnie poprzez panel Płatności BillTech.

### Spis zmiennych konfiguracyjnych w sekcji billtech (billtech.<nazwa_zmiennej>):

##### Zmienne związane z łączeniem się z BillTech (umożliwiające dostęp do API systemu płatności BillTech)

| nazwa zmiennej 	| wartości 	| przykład                         	| opis                                                                                                                                                                                        	|
|----------------	|----------	|----------------------------------	|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------	|
| api_key        	| string   	| Lg8C6zy851WCMSx8d2hctoWIFAwPGlbk 	| Parametr wykorzystywany do uwierzytelnienia HTTP BASIC.                                                                                                                                     	|
| api_secret     	| string   	| fYA9FuqVjMQ4bJIEtNloBMUni1qAKNVi 	| Parametr wykorzystywany do uwierzytelnienia HTTP BASIC.  Otrzymywany po kliknięcie po podaniu parametru PIN i kliknięciu przycisku Generuj API secret w zakładce *Konfiguracja -> BillTech*. 	|
| api_url        	| string   	| https://api.test.billtech.pl     	| Adres do komunikacji z platformą BillTech                                                                                                                                                   	|

##### Zmienne związane z obsługą dokonanej płatności

| nazwa zmiennej     	| wartości 	| przykład 	| opis                                                                                                                  	|
|--------------------	|----------	|----------	|-----------------------------------------------------------------------------------------------------------------------	|
| payment_expiration 	| int      	| 5        	| Liczba dni po których wpłata tymczasowa BillTech znika z systemu. Dla wartości 0 wpłaty tymczasowe nigdy nie znikają. 	|
| cashimport_enabled 	| boolean  	| true     	| Parametr umożliwiający automatyczne rozliczanie opłat tymczasowych poprzez mechanizm cashimport-u.                    	|
| isp_id             	| string   	| dostawca 	| Id dostawcy w systemie BillTech.                                                                                      	|
    
## Kontakt
Więcej informacji na temat naszego API można znaleźć na stronie <docs.billtech.pl>. Po dane do połączenia prosimy o wysyłanie wiadomości na adres <admin@billtech.pl>

Jeżeli chciałbyś przetestować wtyczkę, zobaczyć jak wygląda proces płatności, rozpocząć współpracę lub dowiedzieć się więcej prosimy o wiadomość na adres <kontakt@billtech.pl>
