import fs from "node:fs/promises";
import { FileBlob, SpreadsheetFile } from "@oai/artifact-tool";

const inputPath = "C:/Users/Jake/Downloads/SeniorCare.xlsx";
const outputDir = ".codex/gantt_work";
const input = await FileBlob.load(inputPath);
const workbook = await SpreadsheetFile.importXlsx(input);
const summary = await workbook.inspect({
  kind: "workbook,sheet,table,computedStyle",
  maxChars: 10000,
  tableMaxRows: 20,
  tableMaxCols: 30,
});
console.log(summary.ndjson);
const sheets = workbook.worksheets.items;
for (const sheet of sheets) {
  const preview = await workbook.render({
    sheetName: sheet.name,
    autoCrop: "all",
    scale: 1,
    format: "png",
  });
  await fs.writeFile(`${outputDir}/${sheet.name.replace(/[^a-z0-9]+/gi, "_")}.png`, new Uint8Array(await preview.arrayBuffer()));
}
