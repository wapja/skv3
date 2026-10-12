<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | The Dutch versions of the framework's validation messages (§6.5), with
    | the same keys in the same order as the framework's English file.
    |
    */

    'accepted' => 'Het veld :attribute moet worden geaccepteerd.',
    'accepted_if' => 'Het veld :attribute moet worden geaccepteerd als :other :value is.',
    'active_url' => 'Het veld :attribute moet een geldige URL zijn.',
    'after' => 'Het veld :attribute moet een datum na :date zijn.',
    'after_or_equal' => 'Het veld :attribute moet een datum op of na :date zijn.',
    'alpha' => 'Het veld :attribute mag alleen letters bevatten.',
    'alpha_dash' => 'Het veld :attribute mag alleen letters, cijfers, streepjes en underscores bevatten.',
    'alpha_num' => 'Het veld :attribute mag alleen letters en cijfers bevatten.',
    'any_of' => 'Het veld :attribute is ongeldig.',
    'array' => 'Het veld :attribute moet een lijst zijn.',
    'array_keys' => 'Het veld :attribute mag alleen de volgende sleutels bevatten: :values.',
    'ascii' => 'Het veld :attribute mag alleen alfanumerieke tekens en symbolen van één byte bevatten.',
    'base64' => 'Het veld :attribute moet een geldige Base64-tekenreeks zijn.',
    'before' => 'Het veld :attribute moet een datum vóór :date zijn.',
    'before_or_equal' => 'Het veld :attribute moet een datum op of vóór :date zijn.',
    'between' => [
        'array' => 'Het veld :attribute moet tussen :min en :max items bevatten.',
        'file' => 'Het veld :attribute moet tussen :min en :max kilobytes zijn.',
        'numeric' => 'Het veld :attribute moet tussen :min en :max liggen.',
        'string' => 'Het veld :attribute moet tussen :min en :max tekens lang zijn.',
    ],
    'boolean' => 'Het veld :attribute moet waar of onwaar zijn.',
    'can' => 'Het veld :attribute bevat een waarde waarvoor je geen toestemming hebt.',
    'confirmed' => 'De bevestiging van :attribute komt niet overeen.',
    'contains' => 'Het veld :attribute mist een verplichte waarde.',
    'current_password' => 'Het wachtwoord is onjuist.',
    'date' => 'Het veld :attribute moet een geldige datum zijn.',
    'date_equals' => 'Het veld :attribute moet een datum gelijk aan :date zijn.',
    'date_format' => 'Het veld :attribute moet overeenkomen met het formaat :format.',
    'decimal' => 'Het veld :attribute moet :decimal decimalen hebben.',
    'declined' => 'Het veld :attribute moet worden geweigerd.',
    'declined_if' => 'Het veld :attribute moet worden geweigerd als :other :value is.',
    'different' => 'Het veld :attribute en :other moeten verschillen.',
    'digits' => 'Het veld :attribute moet :digits cijfers lang zijn.',
    'digits_between' => 'Het veld :attribute moet tussen :min en :max cijfers lang zijn.',
    'dimensions' => 'Het veld :attribute heeft ongeldige afbeeldingsafmetingen.',
    'distinct' => 'Het veld :attribute heeft een dubbele waarde.',
    'doesnt_contain' => 'Het veld :attribute mag geen van de volgende waarden bevatten: :values.',
    'doesnt_end_with' => 'Het veld :attribute mag niet eindigen op een van de volgende waarden: :values.',
    'doesnt_start_with' => 'Het veld :attribute mag niet beginnen met een van de volgende waarden: :values.',
    'email' => 'Het veld :attribute moet een geldig e-mailadres zijn.',
    'encoding' => 'Het veld :attribute moet gecodeerd zijn in :encoding.',
    'ends_with' => 'Het veld :attribute moet eindigen op een van de volgende waarden: :values.',
    'enum' => 'De gekozen :attribute is ongeldig.',
    'exists' => 'De gekozen :attribute is ongeldig.',
    'extensions' => 'Het veld :attribute moet een van de volgende extensies hebben: :values.',
    'file' => 'Het veld :attribute moet een bestand zijn.',
    'filled' => 'Het veld :attribute moet een waarde hebben.',
    'gt' => [
        'array' => 'Het veld :attribute moet meer dan :value items bevatten.',
        'file' => 'Het veld :attribute moet groter zijn dan :value kilobytes.',
        'numeric' => 'Het veld :attribute moet groter zijn dan :value.',
        'string' => 'Het veld :attribute moet meer dan :value tekens lang zijn.',
    ],
    'gte' => [
        'array' => 'Het veld :attribute moet :value items of meer bevatten.',
        'file' => 'Het veld :attribute moet groter dan of gelijk aan :value kilobytes zijn.',
        'numeric' => 'Het veld :attribute moet groter dan of gelijk aan :value zijn.',
        'string' => 'Het veld :attribute moet minstens :value tekens lang zijn.',
    ],
    'hex_color' => 'Het veld :attribute moet een geldige hexadecimale kleur zijn.',
    'image' => 'Het veld :attribute moet een afbeelding zijn.',
    'in' => 'De gekozen :attribute is ongeldig.',
    'in_array' => 'Het veld :attribute moet voorkomen in :other.',
    'in_array_keys' => 'Het veld :attribute moet minstens een van de volgende sleutels bevatten: :values.',
    'integer' => 'Het veld :attribute moet een geheel getal zijn.',
    'ip' => 'Het veld :attribute moet een geldig IP-adres zijn.',
    'ipv4' => 'Het veld :attribute moet een geldig IPv4-adres zijn.',
    'ipv6' => 'Het veld :attribute moet een geldig IPv6-adres zijn.',
    'json' => 'Het veld :attribute moet een geldige JSON-tekenreeks zijn.',
    'list' => 'Het veld :attribute moet een lijst zijn.',
    'lowercase' => 'Het veld :attribute moet in kleine letters zijn.',
    'lt' => [
        'array' => 'Het veld :attribute moet minder dan :value items bevatten.',
        'file' => 'Het veld :attribute moet kleiner zijn dan :value kilobytes.',
        'numeric' => 'Het veld :attribute moet kleiner zijn dan :value.',
        'string' => 'Het veld :attribute moet minder dan :value tekens lang zijn.',
    ],
    'lte' => [
        'array' => 'Het veld :attribute mag niet meer dan :value items bevatten.',
        'file' => 'Het veld :attribute moet kleiner dan of gelijk aan :value kilobytes zijn.',
        'numeric' => 'Het veld :attribute moet kleiner dan of gelijk aan :value zijn.',
        'string' => 'Het veld :attribute mag niet meer dan :value tekens lang zijn.',
    ],
    'mac_address' => 'Het veld :attribute moet een geldig MAC-adres zijn.',
    'max' => [
        'array' => 'Het veld :attribute mag niet meer dan :max items bevatten.',
        'file' => 'Het veld :attribute mag niet groter zijn dan :max kilobytes.',
        'numeric' => 'Het veld :attribute mag niet groter zijn dan :max.',
        'string' => 'Het veld :attribute mag niet meer dan :max tekens lang zijn.',
    ],
    'max_digits' => 'Het veld :attribute mag niet meer dan :max cijfers bevatten.',
    'mimes' => 'Het veld :attribute moet een bestand zijn van het type: :values.',
    'mimetypes' => 'Het veld :attribute moet een bestand zijn van het type: :values.',
    'min' => [
        'array' => 'Het veld :attribute moet minstens :min items bevatten.',
        'file' => 'Het veld :attribute moet minstens :min kilobytes zijn.',
        'numeric' => 'Het veld :attribute moet minstens :min zijn.',
        'string' => 'Het veld :attribute moet minstens :min tekens lang zijn.',
    ],
    'min_digits' => 'Het veld :attribute moet minstens :min cijfers bevatten.',
    'missing' => 'Het veld :attribute moet ontbreken.',
    'missing_if' => 'Het veld :attribute moet ontbreken als :other :value is.',
    'missing_unless' => 'Het veld :attribute moet ontbreken, tenzij :other :value is.',
    'missing_with' => 'Het veld :attribute moet ontbreken als :values aanwezig is.',
    'missing_with_all' => 'Het veld :attribute moet ontbreken als :values aanwezig zijn.',
    'multiple_of' => 'Het veld :attribute moet een veelvoud van :value zijn.',
    'not_in' => 'De gekozen :attribute is ongeldig.',
    'not_regex' => 'Het formaat van :attribute is ongeldig.',
    'numeric' => 'Het veld :attribute moet een getal zijn.',
    'password' => [
        'letters' => 'Het veld :attribute moet minstens één letter bevatten.',
        'mixed' => 'Het veld :attribute moet minstens één hoofdletter en één kleine letter bevatten.',
        'numbers' => 'Het veld :attribute moet minstens één cijfer bevatten.',
        'symbols' => 'Het veld :attribute moet minstens één symbool bevatten.',
        'uncompromised' => 'Dit :attribute is gevonden in een datalek. Kies een ander :attribute.',
    ],
    'present' => 'Het veld :attribute moet aanwezig zijn.',
    'present_if' => 'Het veld :attribute moet aanwezig zijn als :other :value is.',
    'present_unless' => 'Het veld :attribute moet aanwezig zijn, tenzij :other :value is.',
    'present_with' => 'Het veld :attribute moet aanwezig zijn als :values aanwezig is.',
    'present_with_all' => 'Het veld :attribute moet aanwezig zijn als :values aanwezig zijn.',
    'prohibited' => 'Het veld :attribute is niet toegestaan.',
    'prohibited_if' => 'Het veld :attribute is niet toegestaan als :other :value is.',
    'prohibited_if_accepted' => 'Het veld :attribute is niet toegestaan als :other is geaccepteerd.',
    'prohibited_if_declined' => 'Het veld :attribute is niet toegestaan als :other is geweigerd.',
    'prohibited_unless' => 'Het veld :attribute is niet toegestaan, tenzij :other in :values staat.',
    'prohibits' => 'Het veld :attribute staat niet toe dat :other aanwezig is.',
    'regex' => 'Het formaat van :attribute is ongeldig.',
    'required' => 'Het veld :attribute is verplicht.',
    'required_array_keys' => 'Het veld :attribute moet waarden bevatten voor: :values.',
    'required_if' => 'Het veld :attribute is verplicht als :other :value is.',
    'required_if_accepted' => 'Het veld :attribute is verplicht als :other is geaccepteerd.',
    'required_if_declined' => 'Het veld :attribute is verplicht als :other is geweigerd.',
    'required_unless' => 'Het veld :attribute is verplicht, tenzij :other in :values staat.',
    'required_with' => 'Het veld :attribute is verplicht als :values aanwezig is.',
    'required_with_all' => 'Het veld :attribute is verplicht als :values aanwezig zijn.',
    'required_without' => 'Het veld :attribute is verplicht als :values niet aanwezig is.',
    'required_without_all' => 'Het veld :attribute is verplicht als geen van :values aanwezig is.',
    'same' => 'Het veld :attribute moet overeenkomen met :other.',
    'size' => [
        'array' => 'Het veld :attribute moet :size items bevatten.',
        'file' => 'Het veld :attribute moet :size kilobytes zijn.',
        'numeric' => 'Het veld :attribute moet :size zijn.',
        'string' => 'Het veld :attribute moet :size tekens lang zijn.',
    ],
    'starts_with' => 'Het veld :attribute moet beginnen met een van de volgende waarden: :values.',
    'string' => 'Het veld :attribute moet tekst zijn.',
    'timezone' => 'Het veld :attribute moet een geldige tijdzone zijn.',
    'unique' => 'Dit :attribute is al in gebruik.',
    'uploaded' => 'Het uploaden van :attribute is mislukt.',
    'uppercase' => 'Het veld :attribute moet in hoofdletters zijn.',
    'url' => 'Het veld :attribute moet een geldige URL zijn.',
    'ulid' => 'Het veld :attribute moet een geldige ULID zijn.',
    'uuid' => 'Het veld :attribute moet een geldige UUID zijn.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Here you may specify custom validation messages for attributes using the
    | convention "attribute.rule" to name the lines. This makes it quick to
    | specify a specific custom language line for a given attribute rule.
    |
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | The Dutch names of the kit's form fields, used in place of the
    | :attribute placeholder.
    |
    */

    'attributes' => [
        'email' => 'e-mailadres',
        'password' => 'wachtwoord',
        'name' => 'naam',
        'current_password' => 'huidige wachtwoord',
        'locale' => 'taal',
    ],

];
