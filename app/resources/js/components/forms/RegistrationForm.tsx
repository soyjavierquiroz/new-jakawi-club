import { Link, useForm } from '@inertiajs/react';
import { useContext, useEffect, useRef, type FormEvent } from 'react';
import { Arrow, ErrorText, Flash } from '../UI';
import { useRegistrationCTA } from '../marketing/primitives';
import { LandingContext } from '../marketing/LandingContext';
import { InternationalPhoneInput } from './InternationalPhoneInput';

export function RegistrationForm({ open, referralCode, landingId }: { open: boolean; referralCode: string | null; landingId?: number }) {
    const cta = useRegistrationCTA();
    const copy = useContext(LandingContext)?.content.registration;
    const form = useForm({ name: '', phone: '', email: '', privacy: true, referral_code: referralCode || '', ...(landingId ? { landing_id: landingId } : {}) });
    const phoneValue = useRef('');
    const submitting = useRef(false);
    const errorFocus = useRef<string | null>(null);
    useEffect(() => {
        if (!form.processing && errorFocus.current && Object.keys(form.errors).length) {
            document.getElementById(errorFocus.current)?.focus();
            errorFocus.current = null;
        }
    }, [form.processing, form.errors]);
    function submit(event: FormEvent) {
        event.preventDefault();
        if (!open || form.processing || submitting.current) return;
        if (!phoneValue.current) { form.setError('phone', 'Revisa el número y el país seleccionado.'); document.getElementById('phone')?.focus(); return; }
        form.transform(data => ({ ...data, phone: phoneValue.current, referral_code: referralCode || '' }));
        submitting.current = true;
        form.post('/registro', {
            preserveScroll: 'errors',
            // Confirmation must open at the top even with the landing's smooth scrolling.
            onSuccess: page => { if (page.component === 'Registered') window.scrollTo({ top: 0, left: 0, behavior: 'instant' }); },
            onFinish: () => { submitting.current = false; },
            onError: errors => { errorFocus.current = Object.keys(errors)[0] || null; },
        });
    }
    return <div id="registro" tabIndex={-1} role="region" aria-labelledby="registration-form-title" className="registration-form rounded-card border border-border bg-surface p-6 shadow-editorial sm:p-9">
        <Flash />
        <h3 id="registration-form-title" className="mb-6 text-xl font-bold">{copy?.form_title ?? 'Tu lugar en JAKAWI'}</h3>
        {copy?.form_subtitle && <p className="mb-6 text-sm leading-relaxed text-text-secondary">{copy.form_subtitle}</p>}
        {referralCode && <p className="mb-5 text-sm text-text-secondary">Llegaste con una invitación JAKAWI.</p>}
        <form onSubmit={submit}>
            <fieldset disabled={!open || form.processing} className="space-y-5">
                <div><label className="sr-only" htmlFor="name">Nombre</label><input className="input" id="name" name="name" placeholder={copy?.name_placeholder ?? 'Tu nombre completo'} autoComplete="name" maxLength={120} required value={form.data.name} onChange={event => form.setData('name', event.target.value)} aria-invalid={!!form.errors.name} aria-describedby={form.errors.name ? 'name-error' : undefined} /><ErrorText id="name-error">{form.errors.name}</ErrorText></div>
                <div><InternationalPhoneInput placeholder={copy?.phone_placeholder} error={form.errors.phone} onChange={phone => { phoneValue.current = phone; form.setData('phone', phone); form.clearErrors('phone'); }} onBlur={() => { if (!phoneValue.current) form.setError('phone', 'Revisa el número y el país seleccionado.'); }} /><ErrorText id="phone-error">{form.errors.phone}</ErrorText><input type="hidden" name="phone" value={form.data.phone} /></div>
                <div><label className="sr-only" htmlFor="email">Correo electrónico</label><input className="input" id="email" name="email" placeholder={copy?.email_placeholder ?? 'Tu correo electrónico'} type="email" autoComplete="email" maxLength={254} required value={form.data.email} onChange={event => form.setData('email', event.target.value)} aria-invalid={!!form.errors.email} aria-describedby={form.errors.email ? 'email-error' : undefined} /><ErrorText id="email-error">{form.errors.email}</ErrorText></div>
                <input type="hidden" name="referral_code" value={form.data.referral_code} />
                <label className="checkbox-label"><input className="checkbox" id="privacy" type="checkbox" name="privacy" required checked={form.data.privacy} onChange={event => form.setData('privacy', event.target.checked)} aria-invalid={!!form.errors.privacy} aria-describedby={form.errors.privacy ? 'privacy-error' : undefined} /><span>Acepto el <Link href="/privacidad" className="underline underline-offset-2">acuerdo de privacidad</Link></span></label>
                <ErrorText id="privacy-error">{form.errors.privacy}</ErrorText><ErrorText>{form.errors.referral_code}</ErrorText>
                <button className="btn btn-primary w-full" disabled={!open || form.processing} aria-busy={form.processing} aria-describedby={!open ? 'registration-status' : undefined} type="submit">{form.processing ? 'Un momento…' : cta}<Arrow /></button>
            </fieldset>
        </form>
        {copy?.submit_microcopy && <p className="mt-3 text-center text-xs text-text-muted" data-submit-microcopy>{copy.submit_microcopy}</p>}
        {!open && <p id="registration-status" role="status" className="mt-3 text-center text-xs text-text-muted">Registro no disponible.</p>}
        <p className="mt-5 text-center text-xs text-text-muted">{copy?.existing_account_label ?? '¿Ya tienes cuenta?'} <Link className="font-semibold underline underline-offset-2" href="/login">{copy?.existing_account_link_label ?? 'Ingresa aquí'}</Link></p>
    </div>;
}
