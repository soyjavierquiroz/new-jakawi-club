import { Link, useForm } from '@inertiajs/react';
import { useEffect, useRef, type FormEvent } from 'react';
import { Arrow, ErrorText, Flash } from '../UI';
import { InternationalPhoneInput } from './InternationalPhoneInput';

export function RegistrationForm({ open, referralCode, landingId }: { open: boolean; referralCode: string | null; landingId?: number }) {
    const form = useForm({ name: '', phone: '', email: '', privacy: false, referral_code: referralCode || '', ...(landingId ? { landing_id: landingId } : {}) });
    const phoneValue = useRef('');
    const errorFocus = useRef<string | null>(null);
    useEffect(() => {
        if (!form.processing && errorFocus.current && Object.keys(form.errors).length) {
            document.getElementById(errorFocus.current)?.focus();
            errorFocus.current = null;
        }
    }, [form.processing, form.errors]);
    function submit(event: FormEvent) {
        event.preventDefault();
        if (!open || form.processing) return;
        if (!phoneValue.current) { form.setError('phone', 'Revisa el número y el país seleccionado.'); document.getElementById('phone')?.focus(); return; }
        form.transform(data => ({ ...data, phone: phoneValue.current, referral_code: referralCode || '' }));
        form.post('/registro', { preserveScroll: true, onError: errors => { errorFocus.current = Object.keys(errors)[0] || null; } });
    }
    return <div id="registro" tabIndex={-1} role="region" aria-labelledby="registration-form-title" className="registration-form rounded-card border border-border bg-surface p-6 shadow-editorial sm:p-9">
        <Flash />
        <div className="mb-6 flex flex-wrap items-center justify-between gap-3"><h3 id="registration-form-title" className="text-xl font-bold">Tu lugar en JAKAWI</h3><span className="rounded-chip bg-background px-3 py-1.5 text-[10px] font-semibold">{open ? 'REGISTROS ABIERTOS' : 'PRÓXIMAMENTE'}</span></div>
        {!open && <div className="message mb-6" role="status"><strong className="block">Estamos preparando la apertura.</strong>Los registros todavía están cerrados. Este formulario se habilitará aquí cuando estemos listos.</div>}
        {referralCode && <p className="mb-5 text-sm text-text-secondary">Llegaste con una invitación JAKAWI.</p>}
        <form onSubmit={submit}>
            <fieldset disabled={!open || form.processing} className="space-y-5 disabled:opacity-65">
                <div><label className="label" htmlFor="name">Nombre</label><input className="input" id="name" name="name" autoComplete="name" maxLength={120} required value={form.data.name} onChange={event => form.setData('name', event.target.value)} aria-invalid={!!form.errors.name} aria-describedby={form.errors.name ? 'name-error' : undefined} /><ErrorText id="name-error">{form.errors.name}</ErrorText></div>
                <div><InternationalPhoneInput error={form.errors.phone} onChange={phone => { phoneValue.current = phone; form.setData('phone', phone); form.clearErrors('phone'); }} onBlur={() => { if (!phoneValue.current) form.setError('phone', 'Revisa el número y el país seleccionado.'); }} /><ErrorText id="phone-error">{form.errors.phone}</ErrorText><input type="hidden" name="phone" value={form.data.phone} /></div>
                <div><label className="label" htmlFor="email">Correo electrónico</label><input className="input" id="email" name="email" type="email" autoComplete="email" maxLength={254} required value={form.data.email} onChange={event => form.setData('email', event.target.value)} aria-invalid={!!form.errors.email} aria-describedby={form.errors.email ? 'email-error' : undefined} /><ErrorText id="email-error">{form.errors.email}</ErrorText></div>
                <input type="hidden" name="referral_code" value={form.data.referral_code} />
                <label className="checkbox-label"><input className="checkbox" id="privacy" type="checkbox" name="privacy" required checked={form.data.privacy} onChange={event => form.setData('privacy', event.target.checked)} aria-invalid={!!form.errors.privacy} aria-describedby={form.errors.privacy ? 'privacy-error' : undefined} /><span>He leído y acepto el <Link href="/privacidad" className="underline underline-offset-2">aviso de privacidad</Link> para crear y gestionar mi cuenta.</span></label>
                <ErrorText id="privacy-error">{form.errors.privacy}</ErrorText><ErrorText>{form.errors.referral_code}</ErrorText>
                <button className="btn btn-primary w-full" aria-busy={form.processing} type="submit">{form.processing ? 'Un momento…' : 'QUIERO SER PARTE DE JAKAWI'}<Arrow /></button>
            </fieldset>
        </form>
        <p className="mt-5 text-center text-xs text-text-muted">¿Ya tienes cuenta? <Link className="font-semibold underline underline-offset-2" href="/login">Ingresa aquí</Link></p>
    </div>;
}
