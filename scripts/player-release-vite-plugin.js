import { createHash } from 'node:crypto';
import {
    lstatSync,
    readFileSync,
    realpathSync,
    writeFileSync,
} from 'node:fs';
import path from 'node:path';

const sha256 = (contents) => createHash('sha256').update(contents).digest('hex');

const safeRelativePath = (value) => (
    typeof value === 'string'
    && value.length > 0
    && !value.includes('\0')
    && !value.includes('\\')
    && !path.posix.isAbsolute(value)
    && value.split('/').every((segment) => segment !== '' && segment !== '.' && segment !== '..')
);

const inside = (root, target) => target === root || target.startsWith(`${root}${path.sep}`);

export default function playerReleasePlugin() {
    let projectRoot;
    let buildDirectory;
    let bundleOutputs;
    let sourceFingerprint;
    let sourceCount;

    return {
        name: 'seasonvar-player-release',
        apply: 'build',
        enforce: 'post',
        configResolved(config) {
            projectRoot = realpathSync(config.root);
            buildDirectory = path.resolve(projectRoot, config.build.outDir);
        },
        buildStart() {
            const descriptorPath = path.join(projectRoot, 'resources/player-release.json');

            if (lstatSync(descriptorPath).isSymbolicLink()) {
                throw new Error('Player release descriptor must not be a symlink.');
            }

            const descriptor = JSON.parse(readFileSync(descriptorPath, 'utf8'));

            if (
                descriptor.schema !== 1
                || !Array.isArray(descriptor.source_files)
                || descriptor.source_files.length === 0
                || new Set(descriptor.source_files).size !== descriptor.source_files.length
            ) {
                throw new Error('Player release descriptor has an invalid schema.');
            }

            const inventory = descriptor.source_files.map((relativePath) => {
                if (!safeRelativePath(relativePath)) {
                    throw new Error('Player release descriptor contains an unsafe source path.');
                }

                let current = projectRoot;

                for (const segment of relativePath.split('/')) {
                    current = path.join(current, segment);

                    if (lstatSync(current).isSymbolicLink()) {
                        throw new Error('Player release source paths must not contain symlinks.');
                    }
                }

                const sourcePath = realpathSync(path.join(projectRoot, relativePath));

                if (!inside(projectRoot, sourcePath)) {
                    throw new Error('Player release source path escapes the project root.');
                }

                const contents = readFileSync(sourcePath);

                return {
                    path: relativePath,
                    sha256: sha256(contents),
                    bytes: contents.length,
                };
            }).sort((left, right) => left.path.localeCompare(right.path));

            sourceFingerprint = sha256(JSON.stringify(inventory));
            sourceCount = inventory.length;
        },
        generateBundle(_options, bundle) {
            bundleOutputs = Object.values(bundle)
                .filter((output) => output.fileName !== 'player-release.json')
                .map((output) => ({
                    file: output.fileName,
                    type: output.type,
                }))
                .sort((left, right) => left.file.localeCompare(right.file));

            this.emitFile({
                type: 'asset',
                fileName: 'player-release.json',
                source: `${JSON.stringify({
                    schema: 1,
                    generated_by: 'player-release-vite-plugin',
                    source_fingerprint: sourceFingerprint,
                    source_count: sourceCount,
                    assets: [],
                }, null, 2)}\n`,
            });
        },
        writeBundle() {
            const assets = bundleOutputs.map((output) => {
                const contents = readFileSync(path.join(buildDirectory, output.file));

                return {
                    file: output.file,
                    sha256: sha256(contents),
                    bytes: contents.length,
                    type: output.type,
                };
            });

            writeFileSync(
                path.join(buildDirectory, 'player-release.json'),
                `${JSON.stringify({
                    schema: 1,
                    generated_by: 'player-release-vite-plugin',
                    source_fingerprint: sourceFingerprint,
                    source_count: sourceCount,
                    assets,
                }, null, 2)}\n`,
            );
        },
    };
}
