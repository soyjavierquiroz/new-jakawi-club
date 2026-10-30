export type Launch = {
    registration_open: boolean;
    video_url: string | null;
    video_poster: string | null;
    event: { title: string; starts_at: string; zoom_url: string | null } | null;
    whatsapp_url: string | null;
};
export type Member = { name: string; email: string; phone: string | null; city: string | null };
export type LandingTemplate = 'LEAD_VSL' | 'WEBINAR' | 'WAITLIST';
export type LandingVideoSettings = { youtube_url: string | null; poster: string | null; aspect_ratio: '3:4'; source_type: 'youtube'; source_aspect_ratio: '9:16' | '3:4' | '16:9'; focal_x: number; focal_y: number };

export type WebinarSection = 'hero' | 'event' | 'benefits' | 'opportunity' | 'early' | 'steps' | 'registration' | 'faq' | 'final';
type WebinarBlock = { title: string; description: string; items: { title: string; description: string }[] };
export type LandingContent = {
    presentation: { template: LandingTemplate; show_video: boolean; show_faq: boolean; show_confirmation_video: boolean; webinar?: { title: string; starts_at: string | null; sections: { id: WebinarSection; visible: boolean }[] } };
    webinar?: { opportunity: { title: string; description: string }; early: WebinarBlock; steps: Omit<WebinarBlock, 'description'> };
    whatsapp_group: { mode: 'default' | 'custom' | 'none'; url: string | null };
    confirmation_whatsapp_group: { mode: 'inherit' | 'default' | 'custom' | 'none'; url: string | null };
    after_registration: { title: string; message: string; instructions: string; cta_label: string };
    next_step: { action: 'none' | 'recover' | 'account' | 'home' | 'whatsapp'; redirect_seconds: 0 | 5 | 10 | 15 | 30 | 60 };
    seo: { title: string; description: string };
    hero: { eyebrow: string; title: string; emphasis: string; ending: string; description: string; cta: string };
    benefits: { eyebrow: string; title: string; description: string; items: { title: string; description: string }[] };
    registration: { eyebrow: string; title: string; description: string };
    faq: { eyebrow: string; title: string; items: { question: string; answer: string }[] };
    final: { eyebrow: string; title: string; description: string; cta: string };
    video: LandingVideoSettings;
    confirmation_video: LandingVideoSettings;
};
export type Landing = { id: number; slug: string; content: LandingContent; preview: boolean; revision: number };
export type LandingRecord = {
    id: number; title: string; slug: string; status: 'draft' | 'published' | 'archived'; campaign: string | null;
    published_campaign: string | null; configuration: LandingContent; published_configuration: LandingContent | null;
    revision: number; published_at: string | null;
};
