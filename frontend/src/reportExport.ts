import type { TDocumentDefinitions } from 'pdfmake/interfaces'

export const reportHeadings = [
  'ID', 'Work Order Title', 'Summary', 'Category', 'Location / Sublocation', 'Department',
  'Author', 'Assigned Role', 'Assigned To', 'Date Replied', 'Date Added', 'Image',
]

function escapeXml(text: string) {
  return text.replace(/[\u0000-\u0008\u000b\u000c\u000e-\u001f]/g, '')
    .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
}

async function workbook(rows: string[][]) {
  const { default: JSZip } = await import('jszip')
  const zip = new JSZip()
  const types = 'http://schemas.openxmlformats.org/package/2006/content-types'
  const office = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships'
  const relations = 'http://schemas.openxmlformats.org/package/2006/relationships'
  const sheet = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'
  zip.file('[Content_Types].xml', '<Types xmlns="'+types+'">'
    + '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    + '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
    + '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
    + '</Types>')
  zip.file('_rels/.rels', '<Relationships xmlns="'+relations+'"><Relationship Id="rId1" Type="'+office
    + '/officeDocument" Target="xl/workbook.xml"/></Relationships>')
  zip.file('xl/workbook.xml', '<workbook xmlns="'+sheet+'" xmlns:r="'+office
    + '"><sheets><sheet name="Work Orders" sheetId="1" r:id="rId1"/></sheets></workbook>')
  zip.file('xl/_rels/workbook.xml.rels', '<Relationships xmlns="'+relations+'"><Relationship Id="rId1" Type="'+office
    + '/worksheet" Target="worksheets/sheet1.xml"/></Relationships>')
  const content = rows.map((row, position) => {
    const number = position + 1
    const cells = row.map((cell, index) => {
      const ref = String.fromCharCode(65 + index) + number
      if (index === 0 && position > 0 && /^\d+$/.test(cell)) return '<c r="'+ref+'"><v>'+cell+'</v></c>'
      return '<c r="'+ref+'" t="inlineStr"><is><t xml:space="preserve">'+escapeXml(cell)+'</t></is></c>'
    }).join('')
    return '<row r="'+number+'">'+cells+'</row>'
  }).join('')
  zip.file('xl/worksheets/sheet1.xml', '<worksheet xmlns="'+sheet+'"><sheetData>'+content+'</sheetData></worksheet>')
  return zip.generateAsync({ type: 'blob', compression: 'DEFLATE',
    mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' })
}

async function pdf(title: string, rows: string[][]) {
  const [module, fonts] = await Promise.all([import('pdfmake/build/pdfmake'), import('pdfmake/build/vfs_fonts')])
  const pdfMake = module.default
  pdfMake.addVirtualFileSystem(fonts.default)
  const document: TDocumentDefinitions = {
    pageSize: 'A3', pageOrientation: 'landscape',
    content: [
      { text: title, fontSize: 18, bold: true, marginBottom: 16 },
      { layout: 'lightHorizontalLines', table: {
        headerRows: 1, widths: [34, '*', '*', 72, 85, 75, 75, 75, 75, 60, 60, 34], body: rows.map(row => [...row]),
      } },
    ],
    defaultStyle: { fontSize: 9 },
  }
  return pdfMake.createPdf(document).getBlob()
}

export async function exportReport(title: string, format: 'pdf' | 'xlsx', data: string[][]) {
  const rows = [reportHeadings, ...data]
  const blob = format === 'pdf' ? await pdf(title, rows) : await workbook(rows)
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.download = title.replaceAll(' ', '-') + '.' + format
  link.href = url
  link.click()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}

