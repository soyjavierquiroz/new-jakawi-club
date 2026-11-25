import type { BenefitIconName } from './lib/benefit-icons';

export type Launch = {
    registration_open: boolean;
    video_url: string | null;
    video_poster: string | null;
    event: { title: string; starts_at: string; zoom_url: string | null } | null;
    whatsapp_url: string | null;
};
export type Member = { name: string; email: string; phone: string | null; city: string | null };
export type LandingTemplate = 'LEAD_VSL' | 'WEBINAR' | 'WAITLIST';
export type LandingVideoSettings = { loop?: boolean; youtube_url: string | null; poster: string | null; aspect_ratio: '3:4'; source_type: 'youtube'; source_aspect_ratio: '9:16' | '3:4' | '16:9'; focal_x: number; focal_y: number };

export type TitleStyle = 'normal' | 'bold' | 'coral' | 'underline-coral' | 'amarillo-acento';
export type TitleSegment = { text: string; style: TitleStyle };
export type EditorialTitle = { title_segments?: TitleSegment[] | null };
export type OpportunityCard = { title: string; description: string; icon?: BenefitIconName; metric?: { value: string; unit: string } | null; condition?: string | null; offer_status?: string | null };

export type WebinarSection = 'hero' | 'event' | 'benefits' | 'opportunity' | 'early' | 'steps' | 'registration' | 'faq' | 'final';
type WebinarBlock = EditorialTitle & { title: string; description: string; items: { title: string; description: string }[] };
export type LandingContent = {
    testimonials?: { name: string; quote: string; authorized: boolean }[];
    commercial?: { image: string | null; description: string | null; public_name?: string | null };
    presentation: { template: LandingTemplate; show_video: boolean; show_faq: boolean; show_confirmation_video: boolean; webinar?: { title: string; starts_at: string | null; sections: { id: WebinarSection; visible: boolean }[] } };
    webinar?: { opportunity: EditorialTitle & { title: string; description: string; image?: { asset_id: string; alt: string; position: 'right'; fit: 'contain' } | null }; early: WebinarBlock; steps: Omit<WebinarBlock, 'description'> };
    whatsapp_group: { mode: 'default' | 'custom' | 'none'; url: string | null };
    confirmation_whatsapp_group: { mode: 'inherit' | 'default' | 'custom' | 'none'; url: string | null };
    after_registration: { title: string; message: string; instructions: string; cta_label: string };
    next_step: { action: 'none' | 'recover' | 'account' | 'home' | 'whatsapp'; redirect_seconds: 0 | 5 | 10 | 15 | 30 | 60 };
    seo: { title: string; description: string };
    hero: EditorialTitle & { emphasis_segments?: TitleSegment[] | null; ending_segments?: TitleSegment[] | null; eyebrow: string; title: string; emphasis: string; ending: string; description: string; cta: string };
    benefits: EditorialTitle & { eyebrow: string; title: string; description: string; items: OpportunityCard[] };
    registration: { eyebrow: string; title: string; description: string; form_title?: string; form_subtitle?: string; name_placeholder?: string; phone_placeholder?: string; email_placeholder?: string; navigation_label?: string | null; submit_label?: string | null; submit_microcopy?: string; existing_account_label?: string; existing_account_link_label?: string };
    faq: EditorialTitle & { eyebrow: string; title: string; items: { question: string; answer: string }[] };
    final: EditorialTitle & { eyebrow: string; title: string; description: string; cta: string };
    video: LandingVideoSettings;
    confirmation_video: LandingVideoSettings;
};
export type Landing = { opportunity_image_url?: string | null; id: number; slug: string; content: LandingContent; preview: boolean; revision: number; social?: { name: string; short_description: string; image_url: string | null; canonical: string; robots: string; site_name: string } };
export type LandingRecord = {
    id: number; title: string; slug: string; status: 'draft' | 'published' | 'archived'; campaign: string | null;
    published_campaign: string | null; configuration: LandingContent; published_configuration: LandingContent | null;
    revision: number; published_at: string | null;
};
