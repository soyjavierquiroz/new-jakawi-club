import type { OpportunityCard as CardContent } from '../../types';
import { BenefitIcon } from './BenefitIcon';
import { benefitIconOptions } from '../../lib/benefit-icons';
import { Play, RotateCcw } from '../media/jakawi-vsl-player/player-icons';
import { Arrow } from '../UI';

// Shared by public channels and the administrator's immediate preview.
export function OpportunityCard({ content, index = 0, webinar = true, preview = false }: {
    content: CardContent; index?: number; webinar?: boolean; preview?: boolean;
}) {
    const { title, description, icon, metric, condition, offer_status } = content;
    return <article className={`feature-card ${metric ? 'opportunity-metric-card' : ''}`} data-opportunity-card data-card-preview={preview || undefined}>
        {metric && <div className="opportunity-metric"><span>{metric.value}</span><span className="opportunity-unit">{metric.unit}</span></div>}
        <span className={webinar ? 'webinar-benefit-icon' : 'benefit-number'} aria-hidden="true">{webinar ? (icon && benefitIconOptions.some(([name]) => name === icon) ? <BenefitIcon name={icon} /> : index % 3 === 0 ? <Play /> : index % 3 === 1 ? <Arrow /> : <RotateCcw />) : <>0{index + 1}</>}</span>
        <h3 className="mb-3 mt-5 text-lg font-extrabold tracking-tight">{title}</h3>
        <p className="text-sm leading-7 text-text-secondary">{description}</p>
        {offer_status && <p className="opportunity-status">{offer_status}</p>}
        {condition && <p className="opportunity-condition">{condition}</p>}
    </article>;
}
