import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { BrandLogo, ErrorText, Flash, Footer, Arrow } from '../components/UI';
import { InternationalPhoneInput } from '../components/forms/InternationalPhoneInput';
type Buyer = { name: string; email: string; phone: string | null };
type Props = { buyer: Buyer | null; signed_in: boolean; active: boolean; purchase: { status: string; qr_ready: boolean; receipt_received: boolean; receipt_pending: boolean; reported: boolean; payment_confirmed: boolean; beneficiary_name: string | null } | null; recipient: string | null; unavailable: boolean; profile_incomplete: boolean };
export default function MembershipCheckout({ buyer, signed_in, active, purchase, recipient, unavailable, profile_incomplete }: Props) {
    const form = useForm({ name: '', email: '', phone: '' });
    const receipt = useForm<{ receipt: File | null }>({ receipt: null });
    const changeAccount = useForm({});
    const firstName = buyer?.name.trim().split(/\s+/u)[0] || '';
    const notice = useForm({ payment_confirmed: false });
    const submittingNotice = useRef(false);
    const resetConfirmation = () => notice.setData('payment_confirmed', false);
    // Inertia history and bfcache can restore stale props; always refresh on entry.
    useEffect(() => {
        let mounted = true;
        const refresh = () => { resetConfirmation(); if (mounted) router.reload(); };
        const onShow = (event: PageTransitionEvent) => { if (event.persisted) refresh(); };
        const onHistory = () => { resetConfirmation(); setTimeout(() => { if (mounted) router.reload(); }, 0); };
        window.addEventListener('pageshow', onShow);
        window.addEventListener('popstate', onHistory);
        router.reload();
        return () => { mounted = false; window.removeEventListener('pageshow', onShow); window.removeEventListener('popstate', onHistory); };
    }, []);
    useEffect(() => { resetConfirmation(); }, [purchase?.status, purchase?.reported, purchase?.beneficiary_name]);
    const pending = !!purchase?.receipt_pending;
    const reported = !!purchase?.reported || pending || purchase?.status === 'PAYMENT_REPORTED';
    const review = !pending && (purchase?.status === 'under_review' || (!!purchase?.receipt_received && purchase.status !== 'rejected'));
    const payable = purchase && !active && !review && !reported && !unavailable && purchase.qr_ready && !!recipient && !!purchase.beneficiary_name;
    return <><Head title="Activa tu membresía · JAKAWI CLUB"><meta head-key="robots" name="robots" content="noindex, nofollow" /></Head>
    <header className="site-header"><div className="shell flex min-h-20 items-center justify-between gap-5"><Link href="/membresia" aria-label="JAKAWI CLUB"><BrandLogo /></Link><span className="text-sm font-semibold text-text-secondary">Membresía</span></div></header>
    <main className="shell min-h-[65vh] py-10 sm:py-16" data-membership-checkout><div className="mx-auto max-w-5xl"><Flash />{signed_in && buyer && <div className="mb-7" data-checkout-identity><p className="text-2xl font-bold">Hola, {firstName}</p><p className="mt-2 text-sm text-text-secondary">¿No eres tú, {firstName}? <button type="button" className="font-semibold underline underline-offset-4" disabled={changeAccount.processing} aria-busy={changeAccount.processing} onClick={() => changeAccount.post('/activar/cambiar-cuenta', { onSuccess: () => { form.reset(); receipt.reset(); resetConfirmation(); } })}>Cambiar de cuenta</button></p></div>}<div className="grid items-start gap-8 lg:grid-cols-[1fr_0.7fr]"><section className="feature-card min-w-0 p-6 sm:p-9">
    {active ? <div role="status"><p className="eyebrow">MEMBRESÍA ACTIVA</p><h1 className="section-title mt-4">Membresía activa</h1><p className="mt-5 text-text-secondary">Tu pago fue confirmado. Ya puedes continuar con el acceso a tu membresía.</p><Link href={signed_in ? '/dashboard' : '/login'} className="btn btn-primary mt-7">Ir a mi cuenta <Arrow /></Link></div> : review ? <div role="status"><p className="eyebrow">PAGO EN REVISIÓN</p><h1 className="section-title mt-4">{purchase?.receipt_received ? 'Comprobante recibido · Verificando pago' : 'Pago en revisión'}</h1><p className="mt-5 text-lg text-text-secondary">Estamos verificando tu pago.</p><p className="mt-4 text-sm text-text-secondary">No necesitas realizar otro pago ni enviar otro archivo.</p><button className="btn btn-secondary mt-7" onClick={() => router.reload()}>Actualizar estado</button></div> : <>
    <p className="eyebrow">{signed_in ? 'PAGO POR QR' : purchase ? '2 · PAGO POR QR' : '1 · TUS DATOS'}</p><h1 className="section-title mt-4">Activa tu membresía</h1>
    {signed_in && !purchase && <div className="message mt-6" role="status">{profile_incomplete ? <><p>Para continuar, actualiza los datos de tu perfil.</p><Link href="/dashboard" className="btn btn-secondary mt-4">Ir a mi perfil</Link></> : <><p>No pudimos preparar tu pago. Vuelve a intentarlo.</p><button className="btn btn-secondary mt-4" onClick={() => router.reload()}>Volver a intentar</button></>}</div>}
    {!purchase && !signed_in && <><p className="mt-5 text-text-secondary">Completa tus datos para continuar al pago.</p><form className="mt-7 space-y-5" onSubmit={event => { event.preventDefault(); form.post('/activar/compra', { preserveScroll: 'errors' }); }}>
    <div><label htmlFor="buyer-name" className="label">Nombre completo</label><input id="buyer-name" name="name" className="input" autoComplete="name" required maxLength={120} value={form.data.name} onChange={e => form.setData('name', e.target.value)} aria-invalid={!!form.errors.name} /><ErrorText>{form.errors.name}</ErrorText></div>
    <div><label htmlFor="buyer-email" className="label">Correo electrónico</label><input id="buyer-email" name="email" type="email" className="input" autoComplete="email" required maxLength={254} value={form.data.email} onChange={e => form.setData('email', e.target.value)} aria-invalid={!!form.errors.email} /><ErrorText>{form.errors.email}</ErrorText></div>
    <div><p className="label">WhatsApp / teléfono</p><InternationalPhoneInput onChange={value => form.setData('phone', value)} onBlur={() => {}} error={form.errors.phone} /><ErrorText>{form.errors.phone}</ErrorText></div>
    <p className="text-xs leading-relaxed text-text-secondary">Usaremos tus datos para gestionar tu compra y tu cuenta. Consulta el <Link href="/privacidad" className="underline">acuerdo de privacidad</Link>.</p>
    <button className="btn btn-primary w-full" disabled={form.processing} aria-busy={form.processing}>Continuar al pago <Arrow /></button>
    </form><p className="mt-5 text-center text-sm text-text-secondary">¿Ya tienes cuenta? <Link href="/login" className="font-semibold underline">Ingresar</Link></p></>}
    {purchase && <>{buyer && !signed_in && <div className="my-6 break-words border-y border-border py-4 text-sm"><p className="font-bold">{buyer.name}</p><p>{buyer.email}</p><p>{buyer.phone}</p></div>}
    {purchase.status === 'rejected' && <p className="message mb-5" role="status">No pudimos validar el comprobante anterior. Revisa tu pago y envía el comprobante correcto.</p>}
    {reported && !active && !review ? <div role="status" className="mt-6">
        <h2 className="text-2xl font-extrabold">¡Recibimos tu aviso de pago!</h2>
        <p className="message mt-5">{purchase.receipt_pending ? 'Pago informado · Falta adjuntar comprobante' : 'Pago informado'}</p>
        <p className="mt-4 text-sm text-text-secondary">Tu aviso quedó registrado. La membresía se activa después de confirmar la transferencia bancaria.</p>
    </div> : payable ? <><p className="mt-5 text-text-secondary">Escanea el QR desde tu aplicación bancaria.</p><p className="mt-3 text-sm">Destinatario: <strong>{recipient}</strong></p><img src="/activar/qr" alt="QR bancario para pagar tu membresía" className="mx-auto my-6 w-full max-w-72 rounded-card" /><a className="btn btn-secondary w-full" href="/activar/qr?download=1" download>Descargar QR</a>
    <p className="mt-5 break-words text-sm">Beneficiario de la membresía: <strong>{purchase.beneficiary_name}</strong></p>
    <form className="mt-6 space-y-4" onSubmit={event => {
        event.preventDefault();
        if (!notice.data.payment_confirmed || notice.processing || submittingNotice.current) return;
        submittingNotice.current = true;
        notice.post('/activar/aviso', { preserveScroll: 'errors', onFinish: () => { submittingNotice.current = false; resetConfirmation(); } });
    }}>
        <label className="flex items-start gap-3 text-sm leading-relaxed" htmlFor="payment-confirmed">
            <input id="payment-confirmed" name="payment_confirmed" type="checkbox" autoComplete="off" className="mt-1 h-5 w-5 shrink-0 accent-brand" checked={notice.data.payment_confirmed} disabled={notice.processing} onChange={event => notice.setData('payment_confirmed', event.target.checked)} />
            <span>Confirmo que ya realicé la transferencia de Bs 100 para activar la membresía JAKAWI de {purchase.beneficiary_name}.</span>
        </label>
        <button className="btn btn-primary w-full" disabled={!notice.data.payment_confirmed || notice.processing} aria-busy={notice.processing}>Ya pagué · Registrar mi aviso</button>
    </form>
    </> : <div className="message mt-6" role="status"><p>El pago no está disponible en este momento. Tu compra se conserva.</p><button className="btn btn-secondary mt-4" onClick={() => router.reload()}>Volver a intentar</button></div>}
    {reported && !active && !review && <form id="comprobante" className="mt-7 scroll-mt-8 space-y-4" onSubmit={event => { event.preventDefault(); receipt.post('/activar/comprobante', { forceFormData: true, preserveScroll: 'errors' }); }}>
        <h2 className="text-2xl font-extrabold">Adjunta tu comprobante</h2>
        <label className="label" htmlFor="receipt">Comprobante de transferencia</label>
        <input id="receipt" name="receipt" type="file" required accept="image/jpeg,image/png,application/pdf" className="input max-w-full text-sm" onChange={event => receipt.setData('receipt', event.target.files?.[0] || null)} />
        <p className="text-xs text-text-secondary">Un archivo JPG, PNG o PDF. Máximo 10 MB. Tu comprobante es privado.</p>
        {receipt.progress && <p role="status">Enviando: {receipt.progress.percentage}%</p>}
        <button className="btn btn-primary w-full" disabled={receipt.processing || !receipt.data.receipt || unavailable} aria-busy={receipt.processing}>Enviar comprobante</button>
    </form>}
    <ErrorText>{notice.errors.payment_confirmed}</ErrorText><ErrorText>{receipt.errors.receipt}</ErrorText></>}
    </>}
    </section><aside className="feature-card min-w-0 p-6 sm:p-9"><p className="eyebrow">TU COMPRA</p><h2 className="mt-4 text-xl font-bold">Membresía JAKAWI CLUB</h2><div className="mt-7 flex justify-between gap-4 border-y border-border py-6"><span>Total</span><strong className="text-3xl font-extrabold">Bs 100</strong></div><p className="mt-5 text-sm leading-relaxed text-text-secondary">Pago por QR bancario. La membresía se activa después de verificar tu pago.</p><Link href="/membresia" className="mt-6 inline-block text-sm underline">Ver información de la membresía</Link></aside></div></div></main><Footer capture /></>;
}
