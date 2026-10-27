// Firma de correo de Estación Diseño: la de siempre (logo, datos, redes, flecha y aviso
// legal), con las imágenes por https y solo estilos en línea, que es lo que respetan
// Gmail y Outlook.

const IMG = 'https://estaciondiseno.es/firmasmail'
const FONT = "'Trebuchet MS', Arial, Helvetica, sans-serif"

const SOCIAL = [
  ['https://www.facebook.com/estaciondisenogranada', 'fbN.png', 'Facebook'],
  ['https://x.com/estacion_diseno', 'x.png', 'X'],
  ['https://www.youtube.com/channel/UC4CDktECMOtOrO9ahdGwinA', 'you.png', 'YouTube'],
  ['https://www.tiktok.com/@estaciondiseno', 'tiktok.png', 'TikTok'],
  ['https://www.linkedin.com/in/estacion-diseno/', 'lin.png', 'LinkedIn'],
]

// El aviso legal, tal cual lo usa la escuela.
const LEGAL = 'Este mensaje va dirigido exclusivamente a su destinatario, en caso de haber recibido este mensaje por error, '
  + 'puede comunicárnoslo mediante el correo electrónico remitido a nuestra atención y procederemos a revisar el motivo del error. '
  + 'Asimismo, el email puede contener documentos y/o información confidencial propia o de terceros por lo que rogamos que se '
  + 'proceda a su eliminación, tanto del email como de los documentos adjuntos para así prevenir mayores fugas de información '
  + 'confidencial. En cumplimiento del Reglamento (UE) 2016/679 del Parlamento Europeo y del Consejo, de 27 de abril de 2016, '
  + 'puede ejercer los derechos de acceso, rectificación, cancelación y oposición (derechos ARCO) a través de: '

const escape = (value) => String(value ?? '').trim()
  .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')

/** Teléfono para el enlace tel: (solo dígitos, con +34 si no lleva prefijo). */
const telHref = (phone) => {
  const digits = String(phone).replace(/\D/g, '')
  return digits.length === 9 ? `tel:+34${digits}` : `tel:+${digits}`
}

/** HTML de la firma con los datos de la persona: { nombre, cargo, email, telefono }. */
export function estacionSignature({ nombre, cargo, email, telefono }) {
  // Cada dato en su línea: así el teléfono no se parte en dos en pantallas estrechas.
  const contact = [
    email && `<a href="mailto:${escape(email)}" style="color:#1c2927;text-decoration:none">${escape(email)}</a>`,
    telefono && `<a href="${telHref(telefono)}" style="color:#1c2927;text-decoration:none;white-space:nowrap">${escape(telefono)}</a>`,
  ].filter(Boolean).join('<br>')
  const icons = SOCIAL.map(([href, img, alt]) => (
    `<a href="${href}" style="text-decoration:none"><img src="${IMG}/${img}" width="24" height="24" alt="${alt}" border="0" style="display:inline-block;border:0;margin-right:4px"></a>`
  )).join('')

  return `<table role="presentation" width="660" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;font-family:${FONT}">
<tr>
<td width="220" valign="middle" style="padding:0 18px 0 0"><a href="https://estaciondiseno.es"><img src="${IMG}/FirmaNueva3.png" width="220" alt="Estación Diseño · Escuela Superior de Diseño" border="0" style="display:block;border:0"></a></td>
<td valign="top" style="padding:2px 12px 0 0;font-size:11px;line-height:1.5;color:#4d4d4d;min-width:250px">
<div style="font-size:16px;font-weight:bold;color:#1c2927;line-height:1.3">${escape(nombre)}</div>
${cargo ? `<div style="font-size:14px;color:#4d4d4d;margin-bottom:2px">${escape(cargo)}</div>` : ''}
${contact ? `<div>${contact}</div>` : ''}
<div style="margin-top:12px;font-size:10px;color:#4d4d4d;white-space:nowrap">Benjamin Franklin 1, 18100 Armilla, Granada</div>
<div style="white-space:nowrap"><a href="https://estaciondiseno.es" style="color:#1c2927;text-decoration:none">www.estaciondiseno.es</a> &nbsp;/&nbsp; <a href="tel:+34958138454" style="color:#1c2927;text-decoration:none">958 13 84 54</a></div>
<div style="margin-top:8px">${icons}</div>
</td>
<td width="170" valign="bottom" style="padding:0"><img src="${IMG}/FlechaNuevaN.png" width="170" height="107" alt="" border="0" style="display:block;border:0"></td>
</tr>
</table>
<table role="presentation" width="560" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;margin-top:14px">
<tr><td style="font-family:Arial, Helvetica, sans-serif;font-size:9px;line-height:1.5;color:#aaaaaa">${LEGAL}<a href="mailto:info@estaciondiseno.es" style="color:#236cda;text-decoration:none">info@estaciondiseno.es</a></td></tr>
</table>`
}
