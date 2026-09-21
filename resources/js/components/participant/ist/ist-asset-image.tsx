import { useIstAssetUrl } from './use-ist-asset-url.ts';
import type { FetchIstAssetUrl } from './ist-asset-url.ts';

/**
 * General-purpose image-option component, built ahead of FA/WU per Lead's
 * 2026-09-21 instruction so those subtests can use it unchanged once their
 * reader ships an `asset_id` per option/item. Renders one image from an
 * `assetId`, reloading the signed URL when it fails to load or is found
 * already expired (see `use-ist-asset-url.ts`'s doc for both paths).
 */

export type IstAssetImageProps = {
    assetId: string;
    fetchAssetUrl: FetchIstAssetUrl;
    /** Required, not optional — an image standing in for an answer
     * option needs a real accessible name, never a decorative blank
     * alt. */
    alt: string;
    className?: string;
};

const ERROR_MESSAGE: Record<string, string> = {
    session_not_found: 'Sesi tidak ditemukan.',
    session_not_started: 'Sesi belum dimulai.',
    session_closed: 'Sesi sudah ditutup.',
    deadline_exceeded: 'Waktu pengerjaan sudah habis.',
    asset_not_found: 'Gambar tidak ditemukan.',
    network_error: 'Gambar gagal dimuat.',
};

/**
 * Remounts `IstAssetImageForOneAsset` with `key={assetId}` whenever
 * `assetId` changes, instead of asking `useIstAssetUrl` to detect the
 * change itself — the standard React way to reset per-instance state on
 * a changing identity, and how `useIstAssetUrl` avoids ever needing a
 * synchronous `setState` inside an effect body (see its own doc).
 */
export function IstAssetImage(props: IstAssetImageProps) {
    return <IstAssetImageForOneAsset key={props.assetId} {...props} />;
}

function IstAssetImageForOneAsset({
    assetId,
    fetchAssetUrl,
    alt,
    className,
}: IstAssetImageProps) {
    const { state, reload } = useIstAssetUrl({ assetId, fetchAssetUrl });

    if (state.status === 'loading') {
        return (
            <div
                role="status"
                aria-busy="true"
                className={`flex items-center justify-center bg-slate-100 text-xs text-slate-500 ${className ?? ''}`}
            >
                Memuat gambar…
            </div>
        );
    }

    if (state.status === 'error') {
        const isRetryable = state.outcome.type === 'network_error';

        return (
            <div
                role="alert"
                className={`flex flex-col items-center justify-center gap-2 bg-slate-100 p-2 text-center text-xs text-slate-600 ${className ?? ''}`}
            >
                <p>{ERROR_MESSAGE[state.outcome.type]}</p>
                {isRetryable && (
                    <button
                        type="button"
                        onClick={reload}
                        className="flex min-h-11 min-w-11 items-center justify-center rounded-md border border-slate-300 bg-white px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50"
                    >
                        Coba lagi
                    </button>
                )}
            </div>
        );
    }

    return (
        <img src={state.url} alt={alt} onError={reload} className={className} />
    );
}
