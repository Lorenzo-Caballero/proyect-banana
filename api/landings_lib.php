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
 * Plantillas por default. Son PRESETS del editor, no layouts distintos:
 * lp.html pinta siempre el mismo esqueleto y estos son los colores/textos con
 * los que arranca una landing nueva antes de que el operador los toque.
 * Viven acá (y no en lp.html) para que el CRM y la página pública lean los
 * mismos defaults de un solo lugar.
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
