<?php
/**
 * landings_lib.php — Landings creadas desde el CRM (migración 52).
 *
 * Cada landing es una fila de `landings` que lp.html pinta en el navegador:
 * plantilla base + bono + colores/textos/imágenes en `config` (JSON). El CRUD
 * lo hace crm_landings.php; la lectura pública, landing_publica.php; y el
 * bono lo cumple recargas_lib.php mirando altas.origen = 'lp:<slug>'.
 *
 * Mismo criterio de tolerancia que publicidad_lib.php: si la migración 52 no
 * corrió, todo devuelve null/[] y loguea — nada del camino de la plata puede
 * romperse porque falte una tabla de estética.
 */

declare(strict_types=1);

/**
 * Plantillas por default. Casi todas son PRESETS del editor, no layouts
 * distintos: lp.html pinta siempre el mismo esqueleto y estos son los
 * colores/textos con los que arranca una landing nueva antes de que el
 * operador los toque. La EXCEPCIÓN es 'registro': misma estructura de config,
 * pero lp.html la reconoce por la clave y pinta SOLO la tarjeta de alta
 * ("Creá tu cuenta"), sin hero ni promo. Viven acá (y no en lp.html) para que
 * el CRM y la página pública lean los mismos defaults de un solo lugar.
 */
function landings_plantillas(): array
{
    $textosBase = [
        'marca'      => 'Tu Casino',
        'pill'       => '🔥 Bono de bienvenida',
        'titulo'     => 'Bono',
        'bajo_cifra' => 'En tu primera carga',
        'sub'        => 'Creá tu cuenta gratis y jugá. Online las 24 horas.',
        'cta'        => 'Jugar ahora',
        'legal'      => 'Jugá con responsabilidad · Solo mayores de 18 años',
    ];
    $imagenes = ['logo' => '', 'fondo' => ''];
    // Escala en % sobre el tamaño base de lp.html (que ya es grande de por
    // sí). Strings a propósito: el merge de landings_config_completa() solo
    // pisa con strings no vacíos, y así una config vieja sin 'tamanos' cae en
    // estos defaults sin caso especial.
    $tamanos = ['cifra' => '100', 'boton' => '100', 'aire' => '100'];

    return [
        'oro' => [
            'nombre'  => 'Oro y violeta',
            'colores' => ['fondo' => '#200a38', 'acento' => '#8b3ffe', 'destacado' => '#ffc844', 'texto' => '#f4ecff'],
            'textos'  => $textosBase,
            'imagenes' => $imagenes,
            'tamanos' => $tamanos,
        ],
        'neon' => [
            'nombre'  => 'Neón',
            'colores' => ['fondo' => '#04120b', 'acento' => '#00c96b', 'destacado' => '#3dffa0', 'texto' => '#eafff4'],
            'textos'  => $textosBase,
            'imagenes' => $imagenes,
            'tamanos' => $tamanos,
        ],
        'fuego' => [
            'nombre'  => 'Fuego',
            'colores' => ['fondo' => '#1c0507', 'acento' => '#e5233d', 'destacado' => '#ffb03a', 'texto' => '#fff1ec'],
            'textos'  => $textosBase,
            'imagenes' => $imagenes,
            'tamanos' => $tamanos,
        ],
        // Layout distinto, no un preset más: lp.html muestra SOLO el
        // formulario "Creá tu cuenta" centrado, sin hero ni promo, y el botón
        // dice siempre "Crear mi cuenta" (nunca nombra el bono). Los textos y
        // tamaños quedan por compatibilidad con el merge, pero no se ven.
        'registro' => [
            'nombre'  => 'Solo registro',
            'colores' => ['fondo' => '#200a38', 'acento' => '#8b3ffe', 'destacado' => '#ffc844', 'texto' => '#f4ecff'],
            'textos'  => $textosBase,
            'imagenes' => $imagenes,
            'tamanos' => $tamanos,
        ],
    ];
}

/**
 * Config completa de una landing: defaults de su plantilla + lo que el
 * operador pisó encima. Merge por sección y por clave (no array_merge plano:
 * un config guardado con la mitad de los textos no puede dejar los otros en
 * blanco en la página publicada).
 */
function landings_config_completa(string $plantilla, ?string $configJson): array
{
    $plantillas = landings_plantillas();
    $base = $plantillas[$plantilla] ?? $plantillas['oro'];
    unset($base['nombre']);

    $propio = json_decode((string)$configJson, true);
    if (!is_array($propio)) {
        return $base;
    }
    foreach (['colores', 'textos', 'imagenes', 'tamanos'] as $seccion) {
        foreach ($base[$seccion] as $k => $v) {
            $valor = $propio[$seccion][$k] ?? null;
            if (is_string($valor) && $valor !== '') {
                $base[$seccion][$k] = $valor;
            }
        }
    }
    return $base;
}

/**
 * Una landing por slug, o null si no existe / falta la migración.
 * $soloActiva=false lo usa recargas_lib.php: el bono prometido se cumple
 * aunque la landing se haya pausado DESPUÉS del registro — pausar corta las
 * altas nuevas, no las promesas ya hechas.
 */
function landings_por_slug(PDO $pdo, string $slug, bool $soloActiva = true): ?array
{
    $slug = trim($slug);
    if ($slug === '') {
        return null;
    }
    try {
        $sql = "SELECT id, slug, nombre, plantilla, bono_pct, activa, config
                  FROM landings WHERE slug = ?" . ($soloActiva ? " AND activa = 1" : "") . " LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute([$slug]);
        $fila = $st->fetch(PDO::FETCH_ASSOC);
        return $fila ?: null;
    } catch (Throwable $e) {
        error_log('landings: no pude leer landings (¿falta la migración 52?): ' . $e->getMessage());
        return null;
    }
}

/** Todas las landings para la lista del CRM, más nuevas primero. */
function landings_listar(PDO $pdo): array
{
    try {
        return $pdo->query(
            "SELECT id, slug, nombre, plantilla, bono_pct, activa, config, creada_en, actualizada_en
               FROM landings ORDER BY id DESC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('landings_listar: ' . $e->getMessage());
        return [];
    }
}

/**
 * Slug único a partir del nombre: minúsculas, a-z0-9-, máximo 24 (el límite
 * lo pone altas.origen, ver la migración 52). Si está tomado se le suma un
 * sufijo numérico. Lo genera SIEMPRE el server y nunca se edita: el slug ya
 * emitido vive en links publicados y en altas.origen de jugadores reales.
 */
function landings_slug_nuevo(PDO $pdo, string $nombre): string
{
    $s = mb_strtolower(trim($nombre));
    // Sacar acentos comunes antes de filtrar: "promoción" -> "promocion".
    $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u']);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim((string)$s, '-');
    $s = mb_substr($s !== '' ? $s : 'landing', 0, 20);   // 20 + '-99' entra en 24
    // El corte a 20 puede caer justo despues de un guion ("promo-de-
    // septiembre-"): un slug con guion final es valido para el server pero
    // los links por WhatsApp/anuncios pierden esa puntuacion final en el
    // camino (y lp.html la pela al normalizar). Mejor no emitirlo nunca.
    $s = trim($s, '-');
    if ($s === '') { $s = 'landing'; }

    $candidato = $s;
    for ($i = 2; $i < 100; $i++) {
        $st = $pdo->prepare("SELECT 1 FROM landings WHERE slug = ? LIMIT 1");
        $st->execute([$candidato]);
        if (!$st->fetchColumn()) {
            return $candidato;
        }
        $candidato = $s . '-' . $i;
    }
    // 99 landings con el mismo nombre no es un caso real: azar y listo.
    return mb_substr($s, 0, 17) . '-' . bin2hex(random_bytes(3));
}

/**
 * Crea o edita una landing. Devuelve [id, slug] o null si falló.
 * Al editar, el slug NO se toca (ver landings_slug_nuevo). $config llega ya
 * como array validado por crm_landings.php; acá solo se serializa.
 */
function landings_guardar(PDO $pdo, ?int $id, string $nombre, string $plantilla,
                          int $bonoPct, array $config): ?array
{
    $nombre = mb_substr(trim($nombre), 0, 80);
    if ($nombre === '') {
        return null;
    }
    if (!isset(landings_plantillas()[$plantilla])) {
        $plantilla = 'oro';
    }
    $bonoPct = max(0, min(200, $bonoPct));
    $json = json_encode($config, JSON_UNESCAPED_UNICODE);

    try {
        if ($id) {
            $st = $pdo->prepare(
                "UPDATE landings SET nombre = ?, plantilla = ?, bono_pct = ?, config = ? WHERE id = ?"
            );
            $st->execute([$nombre, $plantilla, $bonoPct, $json, $id]);
            $st = $pdo->prepare("SELECT slug FROM landings WHERE id = ? LIMIT 1");
            $st->execute([$id]);
            $slug = $st->fetchColumn();
            return $slug ? ['id' => $id, 'slug' => (string)$slug] : null;
        }
        $slug = landings_slug_nuevo($pdo, $nombre);
        $pdo->prepare(
            "INSERT INTO landings (slug, nombre, plantilla, bono_pct, activa, config)
             VALUES (?, ?, ?, ?, 1, ?)"
        )->execute([$slug, $nombre, $plantilla, $bonoPct, $json]);
        return ['id' => (int)$pdo->lastInsertId(), 'slug' => $slug];
    } catch (Throwable $e) {
        error_log('landings_guardar: ' . $e->getMessage());
        return null;
    }
}

/**
 * Pausar/reactivar. Nunca borra: una landing pausada deja de servirse y de
 * crear cuentas, pero su historial (y los bonos ya prometidos) siguen ahí.
 * Devuelve el estado nuevo, o null si no existe.
 */
function landings_toggle(PDO $pdo, int $id): ?bool
{
    try {
        $st = $pdo->prepare("SELECT activa FROM landings WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $actual = $st->fetchColumn();
        if ($actual === false) {
            return null;
        }
        $nuevo = ((int)$actual) ? 0 : 1;
        $pdo->prepare("UPDATE landings SET activa = ? WHERE id = ?")->execute([$nuevo, $id]);
        return (bool)$nuevo;
    } catch (Throwable $e) {
        error_log('landings_toggle: ' . $e->getMessage());
        return null;
    }
}

/**
 * Cuánta historia tiene una landing: registros que trajo y plata de pauta
 * cargada. Es lo que decide si se puede borrar y lo que el CRM muestra al lado
 * del nombre.
 */
function landings_historia(PDO $pdo, string $slug): array
{
    $out = ['altas' => 0, 'gasto' => 0.0];
    $slug = trim($slug);
    if ($slug === '') { return $out; }
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM altas WHERE origen = ?");
        $st->execute(['lp:' . $slug]);
        $out['altas'] = (int)$st->fetchColumn();
    } catch (Throwable $e) { /* best-effort */ }
    try {
        $st = $pdo->prepare("SELECT COALESCE(SUM(monto),0) FROM gasto_diario WHERE landing_slug = ?");
        $st->execute([$slug]);
        $out['gasto'] = (float)$st->fetchColumn();
    } catch (Throwable $e) { /* sin migración 65 no hay gasto por landing */ }
    return $out;
}

/**
 * Borra una landing, SOLO si no tiene historia.
 *
 * POR QUE LA CONDICION (Nahuel, 18/09/2026): *"si tenemos muchísimas landings
 * es medio molesto cuando entramos a ese apartado"*. Y es cierto: al mirar el
 * 18/09 había seis, dos con historia real y cuatro vacías creadas probando —
 * tres de ellas llamadas «50».
 *
 * PERO BORRAR UNA CON HISTORIA ROMPE LOS REPORTES, y no de una forma ruidosa.
 * El vínculo con los datos es el `slug`: `altas.origen` guarda 'lp:<slug>' y
 * `gasto_diario.landing_slug` el mismo texto. Ninguna de esas tablas tiene
 * clave foránea contra `landings` — están sueltas a propósito, para que el
 * historial sobreviva a que alguien edite o pause la landing. El precio es que
 * al borrar la fila esos registros no desaparecen: quedan apuntando a un slug
 * que ya no existe, y Publicidad pasa a mostrar altas y gasto que no se pueden
 * atribuir a nada. El número de la campaña deja de cerrar y no hay forma de
 * saber por qué.
 *
 * Por eso: se borra lo que NO trajo a nadie ni tiene plata cargada (que es
 * justo el desorden que molesta), y lo que sí tiene historia se PAUSA. Pausar
 * la saca de la circulación sin tocar un solo dato.
 */
function landings_borrar(PDO $pdo, int $id): array
{
    if ($id <= 0) { return ['ok' => false, 'error' => 'Falta el id']; }
    try {
        $st = $pdo->prepare("SELECT slug, nombre FROM landings WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $l = $st->fetch(PDO::FETCH_ASSOC);
        if (!$l) { return ['ok' => false, 'error' => 'Esa landing no existe']; }

        $h = landings_historia($pdo, (string)$l['slug']);
        if ($h['altas'] > 0 || $h['gasto'] > 0) {
            return ['ok' => false, 'codigo' => 'tiene_historia',
                    'altas' => $h['altas'], 'gasto' => $h['gasto'],
                    'error' => 'Esta landing trajo ' . $h['altas'] . ' registro(s)'
                             . ($h['gasto'] > 0 ? ' y tiene $' . number_format($h['gasto'], 0, ',', '.')
                                                . ' de pauta cargada' : '')
                             . '. Borrarla dejaría esos datos sin dueño en Publicidad. '
                             . 'Pausala: sale de la lista y el historial queda intacto.'];
        }
        $pdo->prepare("DELETE FROM landings WHERE id = ?")->execute([$id]);
        return ['ok' => true, 'slug' => (string)$l['slug'], 'nombre' => (string)$l['nombre']];
    } catch (Throwable $e) {
        error_log('landings_borrar: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'No se pudo borrar'];
    }
}
