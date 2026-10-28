import { parsePhoneNumberFromString, type CountryCode } from 'libphonenumber-js/max';

/** A pasted international number never changes the manually selected country. */
export function normalizePhone(value: string, country: CountryCode): string {
    const phone = parsePhoneNumberFromString(value, { defaultCountry: country, extract: false });
    return phone?.country === country && !phone.ext && phone.isValid() ? phone.number : '';
}
