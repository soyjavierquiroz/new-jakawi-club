import { getCountries, getCountryCallingCode, type CountryCode } from 'libphonenumber-js/max';
import { useEffect, useMemo, useRef, useState } from 'react';
import { normalizePhone } from './phone';

const names = new Intl.DisplayNames(['es'], { type: 'region' });
const countries = getCountries().map(code => ({ code, name: names.of(code) || code, prefix: getCountryCallingCode(code) })).sort((a, b) => a.name.localeCompare(b.name, 'es'));
const Flag = ({ country }: { country: string }) => <img src={`/marketing/flags/${country}.svg`} alt="" width="24" height="16" loading="lazy" className="country-flag" />;
const searchable = (value: string) => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

export function InternationalPhoneInput({ onChange, error, onBlur }: { onChange: (phone: string) => void; error?: string; onBlur: () => void }) {
    const [country, setCountry] = useState<CountryCode>('BO');
    const [number, setNumber] = useState('');
    const [search, setSearch] = useState('');
    const [active, setActive] = useState(0);
    const [open, setOpen] = useState(false);
    const dialog = useRef<HTMLDialogElement>(null);
    const input = useRef<HTMLInputElement>(null);
    const trigger = useRef<HTMLButtonElement>(null);
    const results = useMemo(() => countries.filter(item => searchable(`${item.name} ${item.code} +${item.prefix}`).includes(searchable(search))), [search]);
    useEffect(() => { if (open) document.getElementById(`country-option-${active}`)?.scrollIntoView({ block: 'nearest' }); }, [active, open]);
    function close() { dialog.current?.close(); setOpen(false); trigger.current?.focus(); }
    function select(code: CountryCode) { setCountry(code); onChange(normalizePhone(number, code)); close(); }
    return <div>
        <label className="sr-only" htmlFor="phone">Teléfono / WhatsApp</label>
        <div className="phone-control">
            <button ref={trigger} type="button" className="phone-country" aria-label={`País: ${names.of(country)} +${getCountryCallingCode(country)}. Cambiar país`} aria-haspopup="dialog" aria-expanded={open} onClick={() => { setSearch(''); setActive(0); setOpen(true); dialog.current?.showModal(); input.current?.focus(); }}><Flag country={country} /><span>+{getCountryCallingCode(country)}</span><span aria-hidden="true">⌄</span></button>
            <input className="input" id="phone" name="phone_national" type="tel" autoComplete="tel-national" inputMode="tel" placeholder="WhatsApp" maxLength={30} required value={number} onChange={event => { setNumber(event.target.value); onChange(normalizePhone(event.target.value, country)); }} onBlur={onBlur} aria-invalid={!!error} aria-describedby={error ? 'phone-error' : undefined} />
        </div>
        <dialog ref={dialog} className="country-dialog" aria-labelledby="country-title" onCancel={event => { event.preventDefault(); close(); }} onClose={() => setOpen(false)} onClick={event => { if (event.target === dialog.current) close(); }}>
            <div className="country-dialog-content">
                <div className="mb-5 flex items-center justify-between gap-3"><h3 id="country-title" className="text-lg font-bold">Elige tu país</h3><button type="button" className="country-close" aria-label="Cerrar selector de país" onClick={close}>✕</button></div>
                <label htmlFor="country-search" className="label">Buscar país o prefijo</label>
                <input ref={input} id="country-search" className="input" type="search" autoComplete="off" placeholder="Bolivia, +591…" role="combobox" aria-autocomplete="list" aria-expanded={open} aria-controls="country-list" aria-activedescendant={results[active] ? `country-option-${active}` : undefined} value={search} onChange={event => { setSearch(event.target.value); setActive(0); }} onKeyDown={event => {
                    if (['ArrowDown', 'ArrowUp', 'Home', 'End', 'Enter'].includes(event.key)) {
                        event.preventDefault();
                        if (event.key === 'Enter' && results[active]) select(results[active].code);
                        else if (event.key === 'ArrowDown') setActive(index => Math.min(index + 1, results.length - 1));
                        else if (event.key === 'ArrowUp') setActive(index => Math.max(index - 1, 0));
                        else if (event.key === 'Home') setActive(0);
                        else if (event.key === 'End') setActive(Math.max(0, results.length - 1));
                    }
                }} />
                <ul id="country-list" role="listbox" aria-label="Países" className="country-list">{results.map((item, index) => <li id={`country-option-${index}`} key={item.code} role="option" aria-selected={country === item.code} data-active={index === active} onPointerMove={() => setActive(index)} onClick={() => select(item.code)}><Flag country={item.code} /><span className="min-w-0 flex-1">{item.name}</span><span>+{item.prefix}</span>{country === item.code && <span aria-hidden="true">✓</span>}</li>)}</ul>
                {!results.length && <p className="py-6 text-sm" role="status">No encontramos ese país. Prueba con su nombre o prefijo.</p>}
            </div>
        </dialog>
    </div>;
}
