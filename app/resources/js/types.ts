export type Launch = {
    registration_open: boolean;
    video_url: string | null;
    video_poster: string | null;
    event: { title: string; starts_at: string; zoom_url: string | null } | null;
    whatsapp_url: string | null;
};
export type Member = { name: string; email: string; phone: string | null; city: string | null };
