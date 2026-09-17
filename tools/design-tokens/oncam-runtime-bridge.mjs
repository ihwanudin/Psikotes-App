import { readFile } from 'node:fs/promises';
import { platform } from 'node:os';
import { resolve as resolvePath } from 'node:path';
import { fileURLToPath } from 'node:url';

export const VIRTUAL_ONCAM_CSS_ID = 'virtual:oncam-design-tokens.css';

const RESOLVED_VIRTUAL_ONCAM_CSS_ID = `\0${VIRTUAL_ONCAM_CSS_ID}`;
const DEFAULT_TOKEN_FILE = fileURLToPath(
    new URL('../../resources/design-tokens/oncam.tokens.json', import.meta.url),
);
const MODES = new Set(['shared', 'light', 'dark']);
const LAYERS = new Set(['primitive', 'semantic', 'component']);
const ALIAS_PATTERN = /^\{([^{}]+)\}$/;
const SAFE_STRING_PATTERN = /^[^;{}\r\n]+$/;
const CSS_NUMBER_PATTERN = /^[+-]?(?:\d+(?:\.\d+)?|\.\d+)$/;
const CASE_INSENSITIVE_PATHS = platform() === 'win32';
const VIRTUAL_IMPORT_PATTERN =
    /@import\s+['"]virtual:oncam-design-tokens\.css['"]\s*;/g;

function fail(path, message) {
    throw new Error(`ONCAM token ${path || '<root>'}: ${message}`);
}

function kebabSegment(segment, path) {
    const normalized = segment
        .replace(/([a-z0-9])([A-Z])/g, '$1-$2')
        .replace(/([A-Z])([A-Z][a-z])/g, '$1-$2')
        .replace(/[^a-zA-Z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .toLowerCase();

    if (!normalized) {
        fail(path, `cannot normalize path segment ${JSON.stringify(segment)}`);
    }

    return normalized;
}

function pathParts(path) {
    const [layer, maybeMode, ...rest] = path.split('.');

    return {
        layer,
        parts: MODES.has(maybeMode) ? rest : [maybeMode, ...rest],
    };
}

function runtimeName(path) {
    const { layer, parts } = pathParts(path);

    return `--oncam-${layer}-${parts
        .map((part) => kebabSegment(part, path))
        .join('-')}`;
}

function collectLeaves(root) {
    const leaves = new Map();

    function walk(node, path, inheritedType) {
        if (!node || typeof node !== 'object' || Array.isArray(node)) {
            fail(path.join('.'), 'groups must be objects');
        }

        const type = node.$type ?? inheritedType;

        if (Object.hasOwn(node, '$value')) {
            const children = Object.keys(node).filter(
                (key) => !key.startsWith('$'),
            );

            if (children.length > 0) {
                fail(
                    path.join('.'),
                    'a leaf cannot also contain token children',
                );
            }

            leaves.set(path.join('.'), {
                path: path.join('.'),
                type,
                value: node.$value,
            });

            return;
        }

        for (const key of Object.keys(node).filter(
            (entry) => !entry.startsWith('$'),
        )) {
            walk(node[key], [...path, key], type);
        }
    }

    for (const layer of LAYERS) {
        if (!root[layer]) {
            fail(layer, 'required layer is missing');
        }

        walk(root[layer], [layer], undefined);
    }

    return leaves;
}

function validateParity(leaves, layer) {
    const light = new Set();
    const dark = new Set();

    for (const path of leaves.keys()) {
        if (path.startsWith(`${layer}.light.`)) {
            light.add(path.slice(`${layer}.light.`.length));
        }

        if (path.startsWith(`${layer}.dark.`)) {
            dark.add(path.slice(`${layer}.dark.`.length));
        }
    }

    for (const relativePath of [...new Set([...light, ...dark])].sort()) {
        if (!light.has(relativePath)) {
            fail(
                `${layer}.light.${relativePath}`,
                `mode parity error; counterpart exists only at ${layer}.dark.${relativePath}`,
            );
        }

        if (!dark.has(relativePath)) {
            fail(
                `${layer}.dark.${relativePath}`,
                `mode parity error; counterpart exists only at ${layer}.light.${relativePath}`,
            );
        }

        const lightLeaf = leaves.get(`${layer}.light.${relativePath}`);
        const darkLeaf = leaves.get(`${layer}.dark.${relativePath}`);

        if (
            lightLeaf?.resolvedType &&
            darkLeaf?.resolvedType &&
            lightLeaf.resolvedType !== darkLeaf.resolvedType
        ) {
            fail(
                `${layer}.dark.${relativePath}`,
                `mode parity type differs from ${layer}.light.${relativePath}: ${darkLeaf.resolvedType} !== ${lightLeaf.resolvedType}`,
            );
        }
    }
}

function aliasTarget(leaf) {
    return typeof leaf.value === 'string'
        ? leaf.value.match(ALIAS_PATTERN)?.[1]
        : undefined;
}

function validateGraph(leaves) {
    const states = new Map();

    function resolve(path, trail = []) {
        const leaf = leaves.get(path);

        if (!leaf) {
            const source = trail.at(-1) ?? '<root>';
            fail(source, `dangling alias targets ${path}`);
        }

        if (states.get(path) === 'visiting') {
            fail(
                path,
                `alias cycle detected: ${[...trail, path].join(' -> ')}`,
            );
        }

        if (states.get(path) === 'visited') {
            return leaf.resolvedType;
        }

        states.set(path, 'visiting');
        const target = aliasTarget(leaf);
        leaf.resolvedType = target
            ? resolve(target, [...trail, path])
            : leaf.type;
        states.set(path, 'visited');

        return leaf.resolvedType;
    }

    for (const leaf of leaves.values()) {
        resolve(leaf.path);
    }

    for (const leaf of leaves.values()) {
        const target = aliasTarget(leaf);
        const [layer, mode] = leaf.path.split('.');

        if (layer === 'primitive') {
            if (target) {
                fail(
                    leaf.path,
                    'primitive authority must contain a raw value, not an alias',
                );
            }

            continue;
        }

        if (!target) {
            fail(leaf.path, `${layer} values must be DTCG aliases`);
        }

        const [targetLayer, targetMode] = target.split('.');

        if (layer === 'semantic' && targetLayer !== 'primitive') {
            fail(
                leaf.path,
                `semantic alias must target primitive authority, received ${target}`,
            );
        }

        if (layer === 'component' && targetLayer !== 'semantic') {
            fail(
                leaf.path,
                `component alias must target semantic authority, received ${target}`,
            );
        }

        if (
            mode === 'shared' &&
            MODES.has(targetMode) &&
            targetMode !== 'shared'
        ) {
            fail(
                leaf.path,
                `shared token cannot target mode-specific alias ${target}`,
            );
        }

        if (
            (mode === 'light' || mode === 'dark') &&
            MODES.has(targetMode) &&
            targetMode !== 'shared' &&
            targetMode !== mode
        ) {
            fail(
                leaf.path,
                `${mode} token cannot target cross-mode alias ${target}`,
            );
        }
    }
}

function assertSafeString(value, path, type) {
    if (typeof value !== 'string' || !SAFE_STRING_PATTERN.test(value.trim())) {
        fail(
            path,
            `unsupported or unsafe ${type} value ${JSON.stringify(value)}`,
        );
    }

    return value.trim();
}

function numericValue(token, suffix = '') {
    if (suffix && !token.endsWith(suffix)) {
        return undefined;
    }

    const number = suffix ? token.slice(0, -suffix.length) : token;

    return CSS_NUMBER_PATTERN.test(number) ? Number(number) : undefined;
}

function inRange(value, minimum, maximum) {
    return value !== undefined && value >= minimum && value <= maximum;
}

function validAlpha(token) {
    const percentage = numericValue(token, '%');

    return token.endsWith('%')
        ? inRange(percentage, 0, 100)
        : inRange(numericValue(token), 0, 1);
}

function validRgbChannel(token) {
    const percentage = numericValue(token, '%');

    return token.endsWith('%')
        ? inRange(percentage, 0, 100)
        : inRange(numericValue(token), 0, 255);
}

function validPercentage(token) {
    return token.endsWith('%') && inRange(numericValue(token, '%'), 0, 100);
}

function validUnitInterval(token) {
    return token.endsWith('%')
        ? inRange(numericValue(token, '%'), 0, 100)
        : inRange(numericValue(token), 0, 1);
}

function validHue(token) {
    for (const unit of ['deg', 'grad', 'rad', 'turn']) {
        if (token.endsWith(unit)) {
            return numericValue(token, unit) !== undefined;
        }
    }

    return numericValue(token) !== undefined;
}

function splitModernColor(body) {
    const slashParts = body.split('/').map((part) => part.trim());

    if (slashParts.length > 2 || slashParts.some((part) => !part)) {
        return undefined;
    }

    const channels = slashParts[0].split(/\s+/);
    const alpha = slashParts[1];

    if (alpha?.includes(' ') || alpha?.includes(',')) {
        return undefined;
    }

    return { channels, alpha };
}

function validFunctionalColor(value) {
    const match = value.match(/^([a-z]+)\(([^()]*)\)$/i);

    if (!match) {
        return false;
    }

    const name = match[1].toLowerCase();
    const body = match[2].trim();

    if (!body || /[^0-9a-zA-Z%+.,/\s-]/.test(body)) {
        return false;
    }

    if (name === 'rgb' || name === 'rgba') {
        if (body.includes(',')) {
            const parts = body.split(',').map((part) => part.trim());
            const expected = name === 'rgba' ? 4 : 3;

            return (
                parts.length === expected &&
                parts.slice(0, 3).every(validRgbChannel) &&
                (parts.length === 3 || validAlpha(parts[3]))
            );
        }

        const parsed = splitModernColor(body);

        return (
            parsed !== undefined &&
            parsed.channels.length === 3 &&
            parsed.channels.every(validRgbChannel) &&
            (parsed.alpha === undefined || validAlpha(parsed.alpha))
        );
    }

    if (name === 'hsl' || name === 'hsla') {
        if (body.includes(',')) {
            const parts = body.split(',').map((part) => part.trim());
            const expected = name === 'hsla' ? 4 : 3;

            return (
                parts.length === expected &&
                validHue(parts[0]) &&
                validPercentage(parts[1]) &&
                validPercentage(parts[2]) &&
                (parts.length === 3 || validAlpha(parts[3]))
            );
        }

        const parsed = splitModernColor(body);

        return (
            parsed !== undefined &&
            parsed.channels.length === 3 &&
            validHue(parsed.channels[0]) &&
            validPercentage(parsed.channels[1]) &&
            validPercentage(parsed.channels[2]) &&
            (parsed.alpha === undefined || validAlpha(parsed.alpha))
        );
    }

    if (
        !['oklch', 'oklab', 'lab', 'lch'].includes(name) ||
        body.includes(',')
    ) {
        return false;
    }

    const parsed = splitModernColor(body);

    if (!parsed || parsed.channels.length !== 3) {
        return false;
    }

    const [lightness, second, third] = parsed.channels;
    const alphaIsValid = parsed.alpha === undefined || validAlpha(parsed.alpha);

    if (!alphaIsValid) {
        return false;
    }

    if (name === 'oklch') {
        return (
            validUnitInterval(lightness) &&
            inRange(numericValue(second), 0, 1) &&
            validHue(third)
        );
    }

    if (name === 'oklab') {
        return (
            validUnitInterval(lightness) &&
            inRange(numericValue(second), -0.5, 0.5) &&
            inRange(numericValue(third), -0.5, 0.5)
        );
    }

    if (name === 'lab') {
        return (
            validPercentage(lightness) &&
            inRange(numericValue(second), -125, 125) &&
            inRange(numericValue(third), -125, 125)
        );
    }

    return (
        validPercentage(lightness) &&
        inRange(numericValue(second), 0, 150) &&
        validHue(third)
    );
}

function serializePrimitive(leaf) {
    const { path, resolvedType: type, value } = leaf;

    if (type === 'color') {
        const serialized = assertSafeString(value, path, type);

        if (
            !/^#(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i.test(
                serialized,
            ) &&
            !validFunctionalColor(serialized)
        ) {
            fail(path, `unsupported color value ${JSON.stringify(value)}`);
        }

        return serialized;
    }

    if (type === 'dimension') {
        const serialized = assertSafeString(value, path, type);

        if (
            !/^-?(?:\d+|\d*\.\d+)(?:px|rem|em|ch|ex|vh|vw|vmin|vmax|%|pt)$/.test(
                serialized,
            )
        ) {
            fail(path, `unsupported dimension value ${JSON.stringify(value)}`);
        }

        return serialized;
    }

    if (type === 'number') {
        if (typeof value !== 'number' || !Number.isFinite(value)) {
            fail(path, `unsupported ${type} value ${JSON.stringify(value)}`);
        }

        return String(value);
    }

    if (type === 'fontWeight') {
        if (
            typeof value !== 'number' ||
            !Number.isInteger(value) ||
            value < 1 ||
            value > 1000
        ) {
            fail(path, `unsupported fontWeight value ${JSON.stringify(value)}`);
        }

        return String(value);
    }

    if (type === 'fontFamily') {
        if (
            !Array.isArray(value) ||
            value.length === 0 ||
            value.some(
                (entry) =>
                    typeof entry !== 'string' ||
                    entry.length === 0 ||
                    !SAFE_STRING_PATTERN.test(entry),
            )
        ) {
            fail(path, `unsupported fontFamily value ${JSON.stringify(value)}`);
        }

        return value.map((entry) => JSON.stringify(entry)).join(', ');
    }

    if (type === 'shadow') {
        const serialized = assertSafeString(value, path, type);

        if (/\b(?:expression|url|var)\s*\(/i.test(serialized)) {
            fail(path, `unsupported shadow value ${JSON.stringify(value)}`);
        }

        return serialized;
    }

    if (type === 'duration') {
        const serialized = assertSafeString(value, path, type);

        if (!/^(?:\d+|\d*\.\d+)(?:ms|s)$/.test(serialized)) {
            fail(path, `unsupported duration value ${JSON.stringify(value)}`);
        }

        return serialized;
    }

    if (type === 'cubicBezier') {
        if (
            !Array.isArray(value) ||
            value.length !== 4 ||
            value.some(
                (entry) => typeof entry !== 'number' || !Number.isFinite(entry),
            ) ||
            value[0] < 0 ||
            value[0] > 1 ||
            value[2] < 0 ||
            value[2] > 1
        ) {
            fail(
                path,
                `unsupported cubicBezier value ${JSON.stringify(value)}`,
            );
        }

        return `cubic-bezier(${value.join(', ')})`;
    }

    fail(path, `unsupported value type ${JSON.stringify(type)}`);
}

function tailwindNamespace(leaf) {
    const lowerPath = leaf.path.toLowerCase();

    if (leaf.resolvedType === 'color') {
        return 'color';
    }

    if (leaf.resolvedType === 'fontFamily') {
        return 'font';
    }

    if (leaf.resolvedType === 'fontWeight') {
        return 'font-weight';
    }

    if (leaf.resolvedType === 'shadow') {
        return 'shadow';
    }

    if (leaf.resolvedType === 'duration') {
        return 'transition-duration';
    }

    if (leaf.resolvedType === 'cubicBezier') {
        return 'ease';
    }

    if (leaf.resolvedType === 'number') {
        if (lowerPath.includes('lineheight')) {
            return 'leading';
        }

        if (lowerPath.includes('opacity')) {
            return 'opacity';
        }

        return 'number';
    }

    if (leaf.resolvedType === 'dimension') {
        if (lowerPath.includes('.font.size.')) {
            return 'text';
        }

        if (lowerPath.includes('tracking')) {
            return 'tracking';
        }

        if (lowerPath.includes('radius')) {
            return 'radius';
        }

        if (lowerPath.includes('border')) {
            return 'border-width';
        }

        return 'spacing';
    }

    fail(
        leaf.path,
        `cannot create Tailwind alias for type ${leaf.resolvedType}`,
    );
}

function declaration(leaf) {
    const target = aliasTarget(leaf);
    const value = target
        ? `var(${runtimeName(target)})`
        : serializePrimitive(leaf);

    return `    ${runtimeName(leaf.path)}: ${value};`;
}

function modeOf(path) {
    const [, candidate] = path.split('.');

    return MODES.has(candidate) ? candidate : 'primitive';
}

function compareDeclarations(left, right) {
    return runtimeName(left.path).localeCompare(runtimeName(right.path), 'en');
}

export function transformOncamTokens(document) {
    if (!document || typeof document !== 'object' || Array.isArray(document)) {
        fail('', 'document must be an object');
    }

    const leaves = collectLeaves(document);
    validateParity(leaves, 'semantic');
    validateParity(leaves, 'component');
    validateGraph(leaves);
    validateParity(leaves, 'semantic');
    validateParity(leaves, 'component');

    const names = new Map();

    for (const leaf of leaves.values()) {
        const name = runtimeName(leaf.path);
        const previous = names.get(name);
        const isModePair =
            previous &&
            new Set([modeOf(previous), modeOf(leaf.path)]).size === 2 &&
            [modeOf(previous), modeOf(leaf.path)].every((mode) =>
                ['light', 'dark'].includes(mode),
            );

        if (previous && previous !== leaf.path && !isModePair) {
            fail(
                leaf.path,
                `runtime name collision with ${previous} at ${name}`,
            );
        }

        names.set(name, leaf.path);
    }

    const all = [...leaves.values()];
    const rootLeaves = all
        .filter((leaf) =>
            ['primitive', 'shared', 'light'].includes(modeOf(leaf.path)),
        )
        .sort(compareDeclarations);
    const darkLeaves = all
        .filter((leaf) => modeOf(leaf.path) === 'dark')
        .sort(compareDeclarations);

    const runtimeLeaves = new Map();

    for (const leaf of rootLeaves) {
        runtimeLeaves.set(runtimeName(leaf.path), leaf);
    }

    const tailwindAliases = [...runtimeLeaves.entries()]
        .map(([name, leaf]) => {
            const { layer, parts } = pathParts(leaf.path);
            const suffix = [layer, ...parts]
                .map((part) => kebabSegment(part, leaf.path))
                .join('-');

            return {
                name: `--${tailwindNamespace(leaf)}-oncam-${suffix}`,
                value: name,
                path: leaf.path,
            };
        })
        .sort((left, right) => left.name.localeCompare(right.name, 'en'));

    const tailwindNames = new Map();

    for (const alias of tailwindAliases) {
        const previous = tailwindNames.get(alias.name);

        if (previous) {
            fail(
                alias.path,
                `Tailwind alias collision with ${previous} at ${alias.name}`,
            );
        }

        tailwindNames.set(alias.name, alias.path);
    }

    const css = [
        ':root {',
        ...rootLeaves.map(declaration),
        '}',
        '',
        '.dark {',
        ...darkLeaves.map(declaration),
        '}',
        '',
        '@theme inline static {',
        ...tailwindAliases.map(
            (alias) => `    ${alias.name}: var(${alias.value});`,
        ),
        '}',
        '',
    ].join('\n');

    const census = { total: all.length };

    for (const layer of LAYERS) {
        census[layer] = all.filter((leaf) =>
            leaf.path.startsWith(`${layer}.`),
        ).length;
    }

    return { css, census };
}

export function oncamTokenRuntimeBridge(options = {}) {
    const tokenFileOption = options.tokenFile ?? DEFAULT_TOKEN_FILE;
    const tokenFile =
        tokenFileOption instanceof URL
            ? fileURLToPath(tokenFileOption)
            : resolvePath(tokenFileOption);

    function normalizedFile(file) {
        const normalized = resolvePath(file).replaceAll('\\', '/');

        return CASE_INSENSITIVE_PATHS ? normalized.toLowerCase() : normalized;
    }

    async function readTokenCss(context) {
        context.addWatchFile(tokenFile);

        let document;

        try {
            document = JSON.parse(await readFile(tokenFile, 'utf8'));
        } catch (error) {
            throw new Error(
                `Unable to read ONCAM token source ${tokenFile}: ${error.message}`,
                { cause: error },
            );
        }

        return transformOncamTokens(document).css;
    }

    return {
        name: 'oncam-token-runtime-bridge',
        enforce: 'pre',
        resolveId(id) {
            if (id === VIRTUAL_ONCAM_CSS_ID) {
                return RESOLVED_VIRTUAL_ONCAM_CSS_ID;
            }
        },
        async load(id) {
            if (id !== RESOLVED_VIRTUAL_ONCAM_CSS_ID) {
                return;
            }

            return readTokenCss(this);
        },
        async transform(code, id) {
            if (!id.split('?', 1)[0].endsWith('.css')) {
                return;
            }

            const imports = code.match(VIRTUAL_IMPORT_PATTERN) ?? [];

            if (imports.length === 0) {
                return;
            }

            if (imports.length > 1) {
                throw new Error(
                    `${VIRTUAL_ONCAM_CSS_ID} must be imported exactly once in ${id}`,
                );
            }

            const css = await readTokenCss(this);

            return {
                code: code.replace(VIRTUAL_IMPORT_PATTERN, css.trimEnd()),
                map: null,
            };
        },
        handleHotUpdate(context) {
            if (normalizedFile(context.file) !== normalizedFile(tokenFile)) {
                return;
            }

            const module = context.server.moduleGraph.getModuleById(
                RESOLVED_VIRTUAL_ONCAM_CSS_ID,
            );

            if (module) {
                context.server.moduleGraph.invalidateModule(module);
            }

            context.server.ws.send({ type: 'full-reload' });

            return module ? [module] : [];
        },
    };
}
