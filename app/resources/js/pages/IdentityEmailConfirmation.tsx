import { Head, useForm } from '@inertiajs/react';
import { PageFrame } from '../components/UI';

export default function IdentityEmailConfirmation({ token }: { token: string }) {
    const form = useForm({ token });
    return <><Head title="Confirmar correo" /><PageFrame><section className="feature-card mx-auto max-w-xl">
        <h1 className="text-2xl font-extrabold">Confirma tu correo para JAKAWI.COM</h1>
        <p className="my-5 text-text-secondary">Confirma que recibiste el enlace en el correo de tu cuenta CLUB. Después podrás activar tu acceso desde tu área privada.</p>
        <form onSubmit={(event) => { event.preventDefault(); form.post('/cuenta/jakawi/confirmar-email'); }}>
            <button className="btn btn-primary" type="submit" disabled={form.processing} aria-busy={form.processing}>CONFIRMAR MI CORREO</button>
            {form.errors.token && <p role="alert" className="mt-4 text-sm">El enlace no es válido. Solicita otro desde tu cuenta.</p>}
        </form>
    </section></PageFrame></>;
}
