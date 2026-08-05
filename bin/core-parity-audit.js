#!/usr/bin/env node

const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');

const root = path.resolve(__dirname, '..');
const counterpartArg = process.argv.find((arg) => arg.startsWith('--counterpart='));
const counterpartRoot = counterpartArg
  ? path.resolve(process.cwd(), counterpartArg.slice('--counterpart='.length))
  : null;
const strictInventory = process.argv.includes('--strict-inventory');
const errors = [];
const warnings = [];

function readJson(filePath) {
  try {
    return JSON.parse(fs.readFileSync(filePath, 'utf8'));
  } catch (error) {
    errors.push(`Cannot read JSON ${filePath}: ${error.message}`);
    return null;
  }
}

function normalize(relativePath) {
  return relativePath.split(path.sep).join('/');
}

function sha256(filePath, normalizeText = false) {
  let content = fs.readFileSync(filePath);
  if (normalizeText) {
    content = Buffer.from(content.toString('utf8').replace(/\r\n/g, '\n'));
  }
  return crypto.createHash('sha256').update(content).digest('hex');
}

function globRegex(pattern) {
  const token = '__DOUBLE_STAR__';
  const escaped = pattern
    .replace(/\\/g, '/')
    .replace(/[.+^${}()|[\]\\]/g, '\\$&')
    .replace(/\*\*/g, token)
    .replace(/\*/g, '[^/]*')
    .replace(/\?/g, '[^/]')
    .replace(new RegExp(token, 'g'), '.*');
  return new RegExp(`^${escaped}$`);
}

function walkFiles(baseRoot, relativeRoot) {
  const absoluteRoot = path.join(baseRoot, relativeRoot);
  if (!fs.existsSync(absoluteRoot)) {
    return [];
  }

  const files = [];
  const pending = [absoluteRoot];
  while (pending.length > 0) {
    const current = pending.pop();
    for (const entry of fs.readdirSync(current, { withFileTypes: true })) {
      const full = path.join(current, entry.name);
      if (entry.isDirectory()) {
        pending.push(full);
      } else if (entry.isFile()) {
        files.push(normalize(path.relative(baseRoot, full)));
      }
    }
  }
  return files;
}

function classifyFiles(baseRoot, manifest) {
  const rules = (manifest.overlayRules || []).map((rule) => ({
    ...rule,
    regex: globRegex(rule.pattern),
    matches: 0,
  }));
  const result = new Map();
  for (const auditRoot of manifest.auditRoots || []) {
    for (const file of walkFiles(baseRoot, auditRoot)) {
      const rule = rules.find((candidate) => candidate.regex.test(file));
      if (rule) {
        rule.matches += 1;
      }
      result.set(file, rule ? rule.classification : manifest.defaultClassification);
    }
  }
  for (const rule of rules) {
    if (rule.matches === 0) {
      errors.push(`Overlay rule does not match a file in ${manifest.edition}: ${rule.pattern}`);
    }
  }
  return result;
}

function validateManifest(baseRoot, manifest) {
  if (!manifest || manifest.schemaVersion !== 1) {
    errors.push(`Unsupported core parity manifest in ${baseRoot}`);
    return new Map();
  }
  if (!['saas', 'self-hosted'].includes(manifest.edition)) {
    errors.push(`Invalid edition in ${baseRoot}`);
  }
  for (const forbiddenPath of manifest.forbiddenPaths || []) {
    if (fs.existsSync(path.join(baseRoot, forbiddenPath))) {
      errors.push(`${manifest.edition} edition contains forbidden path: ${forbiddenPath}`);
    }
  }
  if (manifest.defaultClassification !== 'shared') {
    errors.push(`The default classification must remain shared in ${manifest.edition}`);
  }
  for (const rule of manifest.overlayRules || []) {
    const expected = manifest.edition === 'saas' ? 'saas-overlay' : 'self-hosted-overlay';
    if (rule.classification !== expected) {
      errors.push(`Invalid ${rule.classification} rule for ${manifest.edition}: ${rule.pattern}`);
    }
  }
  for (const relativePath of manifest.exactParityFiles || []) {
    if (!fs.existsSync(path.join(baseRoot, relativePath))) {
      errors.push(`Missing exact parity file in ${manifest.edition}: ${relativePath}`);
    }
  }
  return classifyFiles(baseRoot, manifest);
}

function validateMigrationMap(baseRoot, map) {
  if (!map || map.schemaVersion !== 1) {
    errors.push(`Unsupported migration parity map in ${baseRoot}`);
    return new Set();
  }
  const entries = [...(map.shared || []), ...(map.editionOnly || [])];
  const files = new Set();
  const semanticIds = new Set();
  for (const entry of entries) {
    if (!entry.semanticId || semanticIds.has(entry.semanticId)) {
      errors.push(`Duplicate or missing migration semanticId in ${map.edition}: ${entry.semanticId || '(empty)'}`);
    }
    semanticIds.add(entry.semanticId);
    if (!entry.file || files.has(entry.file)) {
      errors.push(`Duplicate or missing migration file mapping in ${map.edition}: ${entry.file || '(empty)'}`);
      continue;
    }
    files.add(entry.file);
    const filePath = path.join(baseRoot, 'migrations', entry.file);
    if (!fs.existsSync(filePath)) {
      errors.push(`Mapped migration does not exist in ${map.edition}: ${entry.file}`);
      continue;
    }
    const actualHash = sha256(filePath);
    if (actualHash !== entry.sha256) {
      errors.push(`Migration checksum drift in ${map.edition}: ${entry.file}`);
    }
  }

  const diskFiles = fs.readdirSync(path.join(baseRoot, 'migrations'))
    .filter((file) => /\.(php|sql)$/.test(file));
  for (const file of diskFiles) {
    if (!files.has(file)) {
      errors.push(`Migration is not mapped in ${map.edition}: ${file}`);
    }
  }
  for (const file of files) {
    if (!diskFiles.includes(file)) {
      errors.push(`Migration map contains a stale file in ${map.edition}: ${file}`);
    }
  }
  return new Set((map.shared || []).map((entry) => entry.semanticId));
}

function gitHead(baseRoot) {
  try {
    return execFileSync('git', ['-c', `safe.directory=${normalize(baseRoot)}`, '-C', baseRoot, 'rev-parse', 'HEAD'], { encoding: 'utf8' }).trim();
  } catch {
    return null;
  }
}

const manifest = readJson(path.join(root, 'config', 'core-parity-manifest.json'));
const migrationMap = readJson(path.join(root, 'config', 'migration-parity.json'));
const inventory = validateManifest(root, manifest);
const sharedMigrationIds = validateMigrationMap(root, migrationMap);

if (counterpartRoot) {
  const counterpartManifest = readJson(path.join(counterpartRoot, 'config', 'core-parity-manifest.json'));
  const counterpartMigrationMap = readJson(path.join(counterpartRoot, 'config', 'migration-parity.json'));
  const counterpartInventory = validateManifest(counterpartRoot, counterpartManifest);
  const counterpartSharedMigrationIds = validateMigrationMap(counterpartRoot, counterpartMigrationMap);

  if (manifest && counterpartManifest && manifest.edition === counterpartManifest.edition) {
    errors.push('Counterpart must be the other FoxDesk edition.');
  }
  if (manifest && counterpartManifest && manifest.sharedContractVersion !== counterpartManifest.sharedContractVersion) {
    errors.push('Shared contract versions do not match.');
  }

  const localExact = [...(manifest?.exactParityFiles || [])].sort();
  const counterpartExact = [...(counterpartManifest?.exactParityFiles || [])].sort();
  if (JSON.stringify(localExact) !== JSON.stringify(counterpartExact)) {
    errors.push('Exact parity file inventories do not match.');
  } else {
    for (const relativePath of localExact) {
      const localPath = path.join(root, relativePath);
      const remotePath = path.join(counterpartRoot, relativePath);
      if (fs.existsSync(localPath) && fs.existsSync(remotePath) && sha256(localPath, true) !== sha256(remotePath, true)) {
        errors.push(`Exact parity file differs: ${relativePath}`);
      }
    }
  }

  const localSharedMigrations = [...sharedMigrationIds].sort();
  const counterpartSharedMigrations = [...counterpartSharedMigrationIds].sort();
  if (JSON.stringify(localSharedMigrations) !== JSON.stringify(counterpartSharedMigrations)) {
    errors.push('Shared migration semantic IDs do not match.');
  }

  const localSharedFiles = new Set([...inventory].filter(([, kind]) => kind === 'shared').map(([file]) => file));
  const counterpartSharedFiles = new Set([...counterpartInventory].filter(([, kind]) => kind === 'shared').map(([file]) => file));
  const onlyLocal = [...localSharedFiles].filter((file) => !counterpartSharedFiles.has(file));
  const onlyCounterpart = [...counterpartSharedFiles].filter((file) => !localSharedFiles.has(file));
  if (onlyLocal.length || onlyCounterpart.length) {
    const message = `Shared inventory drift: ${onlyLocal.length} only in ${manifest.edition}, ${onlyCounterpart.length} only in ${counterpartManifest.edition}`;
    if (strictInventory) {
      errors.push(message);
    } else {
      warnings.push(message);
    }
  }

  const saasRoot = manifest?.edition === 'saas' ? root : counterpartRoot;
  const publicRoot = manifest?.edition === 'self-hosted' ? root : counterpartRoot;
  const baseline = readJson(path.join(saasRoot, 'config', 'core-baseline.json'));
  const publicVersion = readJson(path.join(publicRoot, 'version.json'));
  if (baseline) {
    if (baseline.sharedContractVersion !== manifest?.sharedContractVersion) {
      errors.push('SaaS core baseline references a different shared contract version.');
    }
    if (publicVersion && baseline.version !== publicVersion.version) {
      errors.push(`Public version ${publicVersion.version} does not match SaaS baseline ${baseline.version}.`);
    }
    const publicHead = gitHead(publicRoot);
    if (publicHead && baseline.commit !== publicHead) {
      errors.push(`Public checkout ${publicHead} does not match pinned SaaS baseline ${baseline.commit}.`);
    }
  }
}

const counts = {};
for (const kind of inventory.values()) {
  counts[kind] = (counts[kind] || 0) + 1;
}

for (const warning of warnings) {
  console.warn(`WARN: ${warning}`);
}
if (errors.length > 0) {
  for (const error of errors) {
    console.error(`ERROR: ${error}`);
  }
  process.exit(1);
}

console.log(`Core parity audit OK (${manifest.edition}): ${JSON.stringify(counts)}`);
