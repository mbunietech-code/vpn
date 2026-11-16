{{-- The admin panel ships no custom Tailwind build, so these pages style themselves. --}}
<style>
    .mt-wrap { display: grid; gap: 1.5rem; }
    .mt-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
    .mt-table th { text-align: left; font-weight: 600; font-size: .75rem; text-transform: uppercase; letter-spacing: .03em;
        color: var(--gray-500); padding: .5rem .75rem; border-bottom: 1px solid var(--gray-200); white-space: nowrap; }
    .mt-table td { padding: .55rem .75rem; border-bottom: 1px solid var(--gray-100); vertical-align: middle; }
    .mt-table td.num, .mt-table th.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .mt-muted { color: var(--gray-500); font-size: .8rem; }
    .mt-bar { height: .5rem; border-radius: 999px; background: var(--gray-100); overflow: hidden; min-width: 6rem; }
    .mt-bar > span { display: block; height: 100%; border-radius: 999px; background: var(--primary-500); }
    .mt-bar > span.calls { background: #16A34A; }
    .mt-bar > span.video { background: #DC2626; }
    .mt-bar > span.downloads { background: #D97706; }
    .mt-scroll { overflow-x: auto; }
    .mt-note { font-size: .85rem; padding: .75rem 1rem; border-radius: .5rem; background: var(--gray-50); color: var(--gray-700); }
    .mt-row { display: flex; flex-wrap: wrap; gap: .75rem; align-items: center; justify-content: space-between; }
    .mt-select { border: 1px solid var(--gray-300); border-radius: .5rem; padding: .35rem 2rem .35rem .6rem; font-size: .875rem;
        background-color: white; color: var(--gray-900); }
    .mt-link { color: var(--primary-600); text-decoration: underline; text-underline-offset: 2px; }
    .dark .mt-table th { color: var(--gray-400); border-color: var(--gray-700); }
    .dark .mt-table td { border-color: var(--gray-800); }
    .dark .mt-bar { background: var(--gray-800); }
    .dark .mt-note { background: var(--gray-800); color: var(--gray-200); }
    .dark .mt-select { background-color: var(--gray-900); color: var(--gray-100); border-color: var(--gray-700); }
    .dark .mt-link { color: var(--primary-400); }
    .dark .mt-muted { color: var(--gray-400); }
</style>
