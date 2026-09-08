import openpyxl
from .common import write


def extract(root):
    wb=openpyxl.load_workbook(root/"PSIKOTEST"/"Skoring"/"Tabel Lookup Skoring Psikotes v1.1.xlsx",data_only=True)
    ws=wb["06 KRP Cutoff ke Skor"]
    bands=[{"factor":ws.cell(r,1).value,"group":ws.cell(r,2).value,"score":int(ws.cell(r,3).value),
            "lo":ws.cell(r,4).value,"hi":ws.cell(r,5).value,"category":ws.cell(r,6).value} for r in range(6,241)]
    data={"version":"F2-2026.09","hanker_formula":"slope_b_x_50",
          "panker_achievement":"correct_plus_incorrect",
          "factor_rounding":{"stage":"before_band_lookup","precision":3,"mode":"half_up"},
          "score_bands":bands}
    write("kraepelin.json",data); return data
